<?php

namespace Brume\Grid\Filters;

use Brume\Grid\Query\FindInSet;
use Brume\Grid\Query\JsonContains;
use Brume\Grid\Query\Like;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * How a filter's value narrows the query: the condition type (equal, like, between, ...) on a
 * column. The column may be 'relation.column' (whereHas) or a JSON path such as 'meta->color'.
 */
final class FilterCondition
{
    /** Operators a group() filter may offer. */
    public const GROUP_OPERATORS = ['equal', 'notEqual', 'gt', 'lt', 'ngt', 'nlt', 'like', 'ilike', 'startWith', 'endWith'];

    /** Conditions that take a list of values (a single value counts as a list of one). */
    public const LIST_CONDITIONS = ['in', 'notIn'];

    protected const COMPARISONS = ['gt' => '>', 'lt' => '<', 'ngt' => '<=', 'nlt' => '>='];

    protected bool $json = false;

    /**
     * @param  list<string>  $operators  group() only
     */
    public function __construct(
        protected string $type,
        protected ?string $column = null,
        protected ?Closure $callback = null,
        protected array $operators = [],
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    /**
     * The column holds a JSON array: in / notIn test whether it contains the values.
     *
     * @internal use Filter::json()
     */
    public function useJson(): void
    {
        if (! in_array($this->type, self::LIST_CONDITIONS, true)) {
            throw new InvalidArgumentException("json() 只能用在 in 或 notIn 篩選，目前是 {$this->type}。");
        }

        $this->json = true;
    }

    /**
     * What differs between databases for this condition, or null when it behaves the same on
     * SQLite, MySQL and PostgreSQL.
     */
    public function driverNote(): ?string
    {
        $types = $this->type === 'group' ? $this->operators : [$this->type];

        $notes = array_filter([
            array_intersect($types, ['like', 'startWith', 'endWith']) !== []
                ? '分不分大小寫取決於資料庫：SQLite 的 LIKE 對英文字母不分大小寫，MySQL 依欄位的 collation（預設 _ci 不分），PostgreSQL 分大小寫。'
                : null,
            in_array('ilike', $types, true)
                ? 'ilike 用 LOWER() 比對：SQLite 的 LOWER() 只轉換英文字母（É 和 é 仍視為不同），PostgreSQL 的 lower() 只接受文字欄位，數字欄位會出錯。'
                : null,
            $this->type === 'findInSet'
                ? 'findInSet 以可攜的 = 和 LIKE 比對逗號分隔的清單（不使用 MySQL 的 FIND_IN_SET），不會用到索引，項目之間不能有空白；大小寫依資料庫的 = 和 LIKE 而定。'
                : null,
            $this->json
                ? 'JSON 包含：MySQL 和 PostgreSQL 用 whereJsonContains；SQLite 不支援，改用 LIKE 比對 JSON 文字裡的 "值"，只找得到字串項目，而且英文字母不分大小寫。'
                : null,
            $this->column !== null && str_contains($this->column, '->')
                ? 'JSON 路徑欄位需要資料庫的 JSON 函式：SQLite 需要 JSON1（3.38 起內建），MySQL 5.7 起，PostgreSQL 要用 json 或 jsonb 欄位。'
                : null,
        ]);

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * Narrow $query by an already validated value: a string, a list of strings, a range
     * {start?, end?} or a condition {op, value}.
     *
     * @param  'text'|'number'|'date'|'datetime'|null  $of  what the input holds (FilterInput::of())
     */
    public function apply(Builder $query, mixed $value, ?string $of = null): void
    {
        // Closures run in a nested where, so an orWhere in them cannot widen the rest of the query.
        if ($this->type === 'where') {
            $query->where(fn (Builder $nested) => ($this->callback)($nested, $value));

            return;
        }

        if ($this->type === 'whereBetween') {
            $query->where(fn (Builder $nested) => ($this->callback)($nested, $value['start'] ?? null, $value['end'] ?? null));

            return;
        }

        [$relation, $column] = $this->split((string) $this->column);

        if ($relation === null) {
            $this->where($query, $query->qualifyColumn($column), $value, $of);

            return;
        }

        $query->whereHas($relation, fn (Builder $related) => $this->where($related, $related->qualifyColumn($column), $value, $of));
    }

    /**
     * 'relation.column' → ['relation', 'column']; 'nested.relation.column->path' keeps the path
     * on the column; a plain column has no relation.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function split(string $column): array
    {
        $head = Str::before($column, '->');
        $path = Str::contains($column, '->') ? '->'.Str::after($column, '->') : '';

        return str_contains($head, '.')
            ? [Str::beforeLast($head, '.'), Str::afterLast($head, '.').$path]
            : [null, $column];
    }

    protected function where(Builder $query, string $column, mixed $value, ?string $of, ?string $type = null): void
    {
        $type ??= $this->type;

        if ($of === 'datetime' && is_string($value)) {
            $value = self::datetime($value);
        }

        match ($type) {
            'equal' => is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value),
            'notEqual' => is_array($value) ? $query->whereNotIn($column, $value) : $query->where($column, '!=', $value),
            'like' => Like::contains($query, $column, $value, caseInsensitive: false),
            'ilike' => Like::contains($query, $column, $value),
            'startWith' => Like::startsWith($query, $column, $value, caseInsensitive: false),
            'endWith' => Like::endsWith($query, $column, $value, caseInsensitive: false),
            'gt', 'lt', 'ngt', 'nlt' => $query->where($column, self::COMPARISONS[$type], $value),
            'in' => $this->json ? JsonContains::apply($query, $column, (array) $value) : $query->whereIn($column, (array) $value),
            'notIn' => $this->json ? JsonContains::apply($query, $column, (array) $value, not: true) : $query->whereNotIn($column, (array) $value),
            'findInSet' => FindInSet::apply($query, $column, $value),
            'date' => $query->whereDate($column, substr($value, 0, 10)),
            'day' => $query->whereDay($column, $value),
            'month' => $query->whereMonth($column, $value),
            'year' => $query->whereYear($column, $value),
            'between' => $this->between($query, $column, $value, $of),
            'group' => $this->where($query, $column, $value['value'], $of, $value['op']),
        };
    }

    /**
     * @param  array{start?: string, end?: string}  $range
     */
    protected function between(Builder $query, string $column, array $range, ?string $of): void
    {
        // A date range covers whole days, so compare the date part of a datetime column.
        $method = $of === 'date' ? 'whereDate' : 'where';

        $bound = fn (string $value, bool $end) => $of === 'datetime' ? self::datetime($value, $end) : $value;

        if (isset($range['start'])) {
            $query->{$method}($column, '>=', $bound($range['start'], false));
        }

        if (isset($range['end'])) {
            $query->{$method}($column, '<=', $bound($range['end'], true));
        }
    }

    /**
     * A datetime-local value ('2026-10-28T09:30') as the database stores it ('2026-10-28 09:30:00'),
     * so it also compares as text on SQLite. An end without seconds covers its whole minute.
     */
    protected static function datetime(string $value, bool $end = false): string
    {
        $value = str_replace('T', ' ', $value);

        return strlen($value) === 16 ? $value.($end ? ':59' : ':00') : $value;
    }
}
