<?php

namespace Brume\Grid\Options;

use Brume\Grid\Query\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Options a remote select loads page by page from a model: GET endpoints.options/{source}
 * ?q=&page=&per_page= answers an OptionsResponse, and ?value[]= turns chosen values back into
 * their labels.
 *
 * The options come from the query given, not from the grid's: on a grid limited to one shop, pass
 * a Builder limited the same way (Customer::where('shop_id', $shop->id)), or every shop's rows are
 * offered.
 */
final class RemoteOptions
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** value[] resolves at most this many values; the rest are left out. */
    public const MAX_VALUES = 100;

    /**
     * @param  list<string>  $searchColumns
     */
    public function __construct(
        protected Builder $query,
        protected string $labelColumn,
        protected string $valueColumn,
        protected array $searchColumns,
    ) {}

    /**
     * @param  class-string<Model>|Builder  $model
     * @param  list<string>  $searchColumns  defaults to the label column
     */
    public static function make(string|Builder $model, string $labelColumn, ?string $valueColumn = null, array $searchColumns = []): self
    {
        $query = is_string($model) ? $model::query() : $model;

        foreach ($searchColumns === [] ? [$labelColumn] : $searchColumns as $column) {
            if (str_contains($column, '.')) {
                throw new InvalidArgumentException("遠端選項的搜尋欄位「{$column}」是關聯欄位，目前還不支援，請改用模型本身的欄位。");
            }
        }

        $options = new self(
            $query,
            $labelColumn,
            $valueColumn ?? $query->getModel()->getKeyName(),
            $searchColumns === [] ? [$labelColumn] : array_values($searchColumns),
        );
        $options->assertVisible();

        return $options;
    }

    /**
     * The label and value reach the page, and a search column answers whether a value matches, so
     * none of them may be an attribute the model hides ($hidden, or left out of $visible), as the
     * list's columns and export refuse them too.
     */
    protected function assertVisible(): void
    {
        $model = $this->query->getModel();
        $hidden = $model->getHidden();
        $visible = $model->getVisible();
        $columns = array_unique([$this->labelColumn, $this->valueColumn, ...$this->searchColumns]);

        foreach ($columns as $column) {
            if (in_array($column, $hidden, true) || ($visible !== [] && ! in_array($column, $visible, true))) {
                throw new InvalidArgumentException(sprintf(
                    '遠端選項用到的「%s」在 %s 的 $hidden 裡（或不在 $visible 裡），選項會把它送到頁面或用來比對搜尋。請改用其他欄位。',
                    $column, $model::class,
                ));
            }
        }
    }

    /**
     * An OptionsResponse. Invalid paging falls back to the defaults; per_page is capped at 50.
     *
     * @return array{data: list<array{value: string, label: string}>, pagination: array{page: int, perPage: int, hasMore: bool}}
     */
    public function respond(Request $request): array
    {
        if ($request->query->has('value')) {
            $values = self::strings($request->query('value'));
            $labels = $this->labels($values);

            return [
                // In the order asked for; values that no longer exist are left out.
                'data' => array_values(array_map(
                    fn (string $value) => ['value' => $value, 'label' => $labels[$value]],
                    array_filter($values, fn (string $value) => isset($labels[$value])),
                )),
                'pagination' => ['page' => 1, 'perPage' => max(1, count($values)), 'hasMore' => false],
            ];
        }

        $page = self::positive($request->query('page'), 1, 999_999);
        $perPage = self::positive($request->query('per_page'), self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);
        $q = is_string($request->query('q')) ? Str::trim($request->query('q')) : '';

        $query = clone $this->query;

        if ($q !== '') {
            $query->where(function (Builder $search) use ($q) {
                foreach ($this->searchColumns as $column) {
                    Like::contains($search, $search->qualifyColumn($column), $q, boolean: 'or');
                }
            });
        }

        // One extra row tells whether another page follows, without counting.
        $rows = $query
            ->orderBy($query->qualifyColumn($this->labelColumn))
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        return [
            'data' => $rows->take($perPage)->map(fn (Model $row) => $this->option($row))->values()->all(),
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'hasMore' => $rows->count() > $perPage],
        ];
    }

    /**
     * The labels of $values in one query, keyed by value.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function labels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $query = clone $this->query;

        return $query
            ->whereIn($query->qualifyColumn($this->valueColumn), $values)
            ->get()
            ->mapWithKeys(fn (Model $row) => [(string) data_get($row, $this->valueColumn) => (string) data_get($row, $this->labelColumn)])
            ->all();
    }

    /**
     * @return array{value: string, label: string}
     */
    protected function option(Model $row): array
    {
        return ['value' => (string) data_get($row, $this->valueColumn), 'label' => (string) data_get($row, $this->labelColumn)];
    }

    protected static function positive(mixed $value, int $default, int $max): int
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,8}$/', $value)) {
            return $default;
        }

        return min((int) $value, $max);
    }

    /**
     * value[]=a&value[]=b, or a single value=a; at most MAX_VALUES of them.
     *
     * @return list<string>
     */
    protected static function strings(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        $values = array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && $v !== '')));

        return array_slice($values, 0, self::MAX_VALUES);
    }
}
