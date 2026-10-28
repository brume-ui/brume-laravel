<?php

namespace Brume\Grid;

use Brume\Support\ComponentRef;
use InvalidArgumentException;

/**
 * What an expand() or modal() cell opens (a CellContent): text, a small table or a frontend
 * component. It is built for every row of the current page and sent in row._meta.content[key].
 */
final class Content
{
    /**
     * @param  array<string, mixed>  $schema
     */
    private function __construct(private array $schema) {}

    public static function text(string $text): self
    {
        return new self(['type' => 'text', 'text' => $text]);
    }

    /**
     * A table of $rows (arrays, objects or models), showing only the declared columns.
     *
     * @param  array<string, string>  $columns  key => label
     * @param  iterable<mixed>  $rows
     */
    public static function table(array $columns, iterable $rows): self
    {
        return new self([
            'type' => 'table',
            'columns' => self::tableColumns($columns),
            'rows' => self::tableRows($columns, $rows),
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    public static function component(string $name, array $props = []): self
    {
        return new self(ComponentRef::make($name, $props));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->schema;
    }

    /**
     * key => label as the schema's [{key, label}].
     *
     * @internal
     *
     * @param  array<string, string>  $columns
     * @return list<array{key: string, label: string}>
     */
    public static function tableColumns(array $columns): array
    {
        if ($columns === [] || array_is_list($columns)) {
            throw new InvalidArgumentException('表格需要至少一個欄位，格式是 key => 標籤，例如 [\'sku\' => \'品號\']。');
        }

        $list = [];

        foreach ($columns as $key => $label) {
            $list[] = ['key' => (string) $key, 'label' => (string) $label];
        }

        return $list;
    }

    /**
     * Each row cut down to the declared keys, so nothing else on the models (a password, say)
     * reaches the page.
     *
     * @internal
     *
     * @param  array<string, string>  $columns
     * @param  iterable<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function tableRows(array $columns, iterable $rows): array
    {
        $table = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach (array_keys($columns) as $key) {
                $cells[(string) $key] = Column::normalize(data_get($row, (string) $key));
            }

            $table[] = $cells;
        }

        return $table;
    }
}
