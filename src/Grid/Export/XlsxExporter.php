<?php

namespace Brume\Grid\Export;

use Brume\Grid\Column;
use Brume\Grid\Grid;
use Brume\Grid\GridQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Answers endpoints.export: an .xlsx of the grid's exportable columns, for export_scope
 * - all (or any other value): every row matching the current search and filters, read 500 at a time;
 * - page: the page the list shows;
 * - selected: the rows of ids[] among those (an id the list could not show is skipped), at most
 *   the grid's largest page of them, as the selection only ever holds rows of the shown page.
 * Rows come in the list's order. Text is always written as text, never as a formula. The first
 * row is checked before the response starts; an error later on ends the file with a 匯出中斷 row.
 *
 * @internal
 */
class XlsxExporter
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @throws ValidationException when ids[] holds more ids than the largest page
     */
    public function stream(Grid $grid, Request $request): StreamedResponse
    {
        $state = GridQuery::fromRequest($request, $grid);
        $columns = $grid->exportColumns();

        $rows = match ($request->query('export_scope')) {
            'page' => $grid->currentPage($state)->getCollection(),
            'selected' => $this->selected($grid, $state, $request),
            default => $grid->queryFor($state)->lazy(500),
        };

        // Once streaming starts the 200 is sent, so a misconfigured column ($hidden, a value that is
        // no scalar) fails here, on the first row, as an ordinary error response.
        $first = $rows instanceof LazyCollection ? $grid->queryFor($state)->first() : collect($rows)->first();

        if ($first !== null) {
            $this->values($columns, $first);
        }

        $filename = $grid->exportFilename();

        return new StreamedResponse(function () use ($columns, $rows) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(new Row(
                array_map(fn (Column $column) => new Cell\StringCell($column->heading(), null), $columns),
                (new Style)->setFontBold(),
            ));

            try {
                foreach ($rows as $model) {
                    $writer->addRow(new Row(array_map($this->cell(...), $this->values($columns, $model))));
                }
            } catch (Throwable $e) {
                // Too late for an error response: log it and end the file with a row saying so. The
                // message (SQL, paths…) goes in the file only with APP_DEBUG; the log always has it.
                Log::error('Brume 匯出 Excel 時中斷：'.$e->getMessage(), ['exception' => $e]);
                $notice = app()->hasDebugModeEnabled()
                    ? __('brume::grid.export.interrupted', ['message' => $e->getMessage()])
                    : __('brume::grid.export.interrupted_generic');
                $writer->addRow(new Row([new Cell\StringCell($notice, null)]));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => self::CONTENT_TYPE,
            'Content-Disposition' => $this->disposition($filename),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return iterable<Model>
     */
    protected function selected(Grid $grid, GridQuery $state, Request $request): iterable
    {
        $lookup = $grid->rows();
        $given = $request->query('ids');
        $ids = is_array($given) ? array_values(array_unique(array_filter(
            array_map(fn (mixed $id) => $lookup->normalizeId($id), $given),
            fn (int|string|null $id) => $id !== null,
        ))) : [];

        if (count($ids) > ($max = $grid->maxBatchSize())) {
            throw ValidationException::withMessages(['ids' => __('brume::grid.export.too_many', ['max' => $max])]);
        }

        return $ids === [] ? [] : $grid->queryFor($state)->whereKey($ids)->get()->unique();
    }

    /**
     * @param  list<Column>  $columns
     * @return list<string|int|float|bool|null>
     */
    protected function values(array $columns, Model $model): array
    {
        return array_map(fn (Column $column) => $column->exportValue($model), $columns);
    }

    /**
     * A string is always a StringCell: Cell::fromValue() would turn "=..." into a formula.
     */
    protected function cell(string|int|float|bool|null $value): Cell
    {
        return is_string($value) && $value !== '' ? new Cell\StringCell($value, null) : Cell::fromValue($value);
    }

    /**
     * attachment with an ASCII fallback name and the UTF-8 name in RFC 5987's filename*.
     */
    protected function disposition(string $filename): string
    {
        // One "_" per character (not per byte) outside printable ASCII, and for " \ %.
        $fallback = preg_replace('/[^\x20-\x7E]|["\\\\%]/u', '_', $filename) ?? 'export.xlsx';

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($filename));
    }
}
