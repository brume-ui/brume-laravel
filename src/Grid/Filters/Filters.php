<?php

namespace Brume\Grid\Filters;

use Brume\Grid\Column;
use Brume\Grid\Options\RemoteOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * A grid's filters: the panel (with its layout), column-header filters, hidden filters,
 * selectors and scopes. Each filter reads filter[key] from the URL; keys are unique per grid.
 *
 * Conditions take the column to filter as $column (default: the key), which may be
 * 'relation.column' (whereHas) or a JSON path such as 'meta->color'.
 */
class Filters
{
    /** @var list<Filter|'newline'> the panel's filters and line breaks, in declaration order */
    protected array $layout = [];

    protected int $columns = 3;

    /** @var array<string, Filter|Selector> every filter read from filter[key], in declaration order */
    protected array $filters = [];

    /** @var array<string, Scope> */
    protected array $scopes = [];

    public function equal(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('equal', $key, $label, $column);
    }

    public function notEqual(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('notEqual', $key, $label, $column);
    }

    /**
     * Contains $value; case follows the database (see driverNote()).
     */
    public function like(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('like', $key, $label, $column);
    }

    /**
     * Contains $value, ignoring case.
     */
    public function ilike(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('ilike', $key, $label, $column);
    }

    public function startWith(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('startWith', $key, $label, $column);
    }

    public function endWith(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('endWith', $key, $label, $column);
    }

    /**
     * Greater than.
     */
    public function gt(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('gt', $key, $label, $column);
    }

    /**
     * Less than.
     */
    public function lt(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('lt', $key, $label, $column);
    }

    /**
     * Not greater than: <=.
     */
    public function ngt(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('ngt', $key, $label, $column);
    }

    /**
     * Not less than: >=.
     */
    public function nlt(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('nlt', $key, $label, $column);
    }

    /**
     * One of several values; give the choices with ->multipleSelect([...]) or ->checkbox([...]). ->json() matches a JSON array column.
     */
    public function in(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('in', $key, $label, $column);
    }

    /**
     * None of several values; give the choices with ->multipleSelect([...]) or ->checkbox([...]).
     */
    public function notIn(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('notIn', $key, $label, $column);
    }

    /**
     * One item of a comma-separated column ('1,12,3'), portable across databases.
     */
    public function findInSet(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('findInSet', $key, $label, $column);
    }

    /**
     * The date part equals a date picked with a date input.
     */
    public function date(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('date', $key, $label, $column);
    }

    /**
     * Day of the month, 1–31.
     */
    public function day(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('day', $key, $label, $column);
    }

    /**
     * Month, 1–12.
     */
    public function month(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('month', $key, $label, $column);
    }

    /**
     * Year, typed as a number.
     */
    public function year(string $key, string $label, ?string $column = null): Filter
    {
        return $this->simple('year', $key, $label, $column);
    }

    /**
     * A range: filter[key][start] and/or filter[key][end], inclusive. ->number() or ->date()
     * sets what the range holds.
     */
    public function between(string $key, string $label, ?string $column = null): Filter
    {
        return $this->panel(new Filter($key, $label, new FilterCondition('between', $column ?? $key), FilterInput::range()));
    }

    /**
     * A range applied by $apply: fn (Builder $query, ?string $start, ?string $end).
     */
    public function whereBetween(string $key, string $label, Closure $apply): Filter
    {
        return $this->panel(new Filter($key, $label, new FilterCondition('whereBetween', callback: $apply), FilterInput::range()));
    }

    /**
     * A value applied by $apply: fn (Builder $query, mixed $value); a text input unless set.
     */
    public function where(string $key, string $label, Closure $apply): Filter
    {
        return $this->panel(new Filter($key, $label, new FilterCondition('where', callback: $apply), FilterInput::text()));
    }

    /**
     * An operator picked next to the value: filter[key][op] and filter[key][value].
     *
     * @param  list<string>  $operators  of equal, notEqual, gt, lt, ngt, nlt, like, ilike, startWith, endWith
     */
    public function group(string $key, string $label, array $operators = ['equal', 'gt', 'lt', 'ngt', 'nlt'], ?string $column = null): Filter
    {
        $operators = array_values($operators);
        $unknown = array_diff($operators, FilterCondition::GROUP_OPERATORS);

        if ($operators === [] || $unknown !== []) {
            throw new InvalidArgumentException("group() 篩選「{$label}」的運算子只能是 ".implode('、', FilterCondition::GROUP_OPERATORS).'，目前有：'.implode('、', $unknown ?: ['（空的）']).'。');
        }

        return $this->panel(new Filter($key, $label, new FilterCondition('group', $column ?? $key, operators: $operators), FilterInput::group($operators)));
    }

    /**
     * An equal filter that applies (and shows its chip) but has no input anywhere, for links
     * such as ?filter[customer_id]=5.
     */
    public function hidden(string $key, ?string $column = null): Filter
    {
        return $this->register(new Filter($key, Column::defaultLabel($key), new FilterCondition('equal', $column ?? $key), FilterInput::text()))
            ->placeIn('hidden');
    }

    /**
     * A named subset (?scope=key): fn (Builder $query).
     */
    public function scope(string $key, string $label, Closure $apply): static
    {
        if (isset($this->scopes[$key])) {
            throw new InvalidArgumentException("範圍「{$key}」重複宣告了，同一個列表內 scope 的 key 不能重複。");
        }

        $this->scopes[$key] = new Scope(self::checkKey($key), $label, $apply);

        return $this;
    }

    /**
     * Pills above the table: one choice filters with equal, $multiple with in.
     *
     * @param  array<int|string, string>  $options  value => label
     */
    public function selector(string $key, string $label, array $options, bool $multiple = false, ?string $column = null): static
    {
        $this->register(new Selector($key, $label, $options, $multiple, new FilterCondition($multiple ? 'in' : 'equal', $column ?? $key)));

        return $this;
    }

    /**
     * The next panel filter starts a new row.
     */
    public function newline(): static
    {
        $this->layout[] = 'newline';

        return $this;
    }

    /**
     * How many columns the panel lays its filters out in: 1–4, 3 by default.
     */
    public function columns(int $n): static
    {
        if ($n < 1 || $n > 4) {
            throw new InvalidArgumentException("篩選面板的 columns() 只能是 1 到 4，目前是 {$n}。");
        }

        $this->columns = $n;

        return $this;
    }

    /**
     * The filter in a column header (Column::filter()).
     *
     * @internal
     *
     * @param  array<int|string, string>  $options  value => label, for in, checkbox and equal
     */
    public function forColumn(string $key, string $label, string $type, array $options, bool $numeric): Filter
    {
        [$condition, $input] = match ($type) {
            'between' => ['between', FilterInput::range()],
            'checkbox' => ['in', FilterInput::choice('checkbox', $options)],
            'equal' => ['equal', $options === [] ? FilterInput::text() : FilterInput::choice('select', $options)],
            'in' => ['in', FilterInput::choice('multipleSelect', $options)],
            'input' => ['like', FilterInput::text()],
            'like', 'startWith', 'gt', 'lt', 'ngt', 'nlt' => [$type, FilterInput::text()],
            default => throw new InvalidArgumentException("欄位「{$key}」的 filter() 類型只能是 between、checkbox、equal、gt、in、input、like、lt、ngt、nlt、startWith，目前是「{$type}」。"),
        };

        if ($numeric) {
            $input = $input->asNumber() ?? $input;
        }

        return $this->register(new Filter($key, $label, new FilterCondition($condition, $key), $input))->placeIn('column');
    }

    /**
     * Whether a filter loads its options through the `_brume` options endpoint.
     *
     * @internal
     */
    public function hasRemote(): bool
    {
        return array_filter($this->filters, fn ($filter) => $filter instanceof Filter && $filter->filterInput()->remoteOptions() !== null) !== [];
    }

    /**
     * The remote options of the filter keyed $key, if it has them.
     *
     * @internal
     */
    public function remoteOptions(string $key): ?RemoteOptions
    {
        $filter = $this->filters[$key] ?? null;

        return $filter instanceof Filter ? $filter->filterInput()->remoteOptions() : null;
    }

    /**
     * The usable values of the request's `filter` parameter, keyed by filter. Defaults apply only
     * when $useDefaults, i.e. the request has no `filter` parameter at all, not even a bare
     * `filter=` (Filter::default()).
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function values(mixed $filter, bool $useDefaults = false): array
    {
        $filter = is_array($filter) ? $filter : [];
        $values = [];

        foreach ($this->filters as $key => $definition) {
            $value = $definition->parse($filter[$key] ?? null, $useDefaults);

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The scope key ?scope= asks for, when the grid has it.
     *
     * @internal
     */
    public function scopeKey(mixed $scope): ?string
    {
        return is_string($scope) && isset($this->scopes[$scope]) ? $scope : null;
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $values  from values()
     */
    public function apply(Builder $query, array $values, ?string $scope): void
    {
        if ($scope !== null) {
            $this->scopes[$scope]->apply($query);
        }

        foreach ($values as $key => $value) {
            $this->filters[$key]->apply($query, $value);
        }
    }

    /**
     * One AppliedFilter per applied scope and filter.
     *
     * @internal
     *
     * @param  array<string, mixed>  $values  from values()
     * @return list<array{key: string, label: string, display: string}>
     */
    public function applied(array $values, ?string $scope): array
    {
        $applied = $scope !== null
            ? [['key' => 'scope', 'label' => __('brume::grid.scope'), 'display' => $this->scopes[$scope]->label()]]
            : [];

        foreach ($values as $key => $value) {
            $filter = $this->filters[$key];
            $applied[] = ['key' => $key, 'label' => $filter->label(), 'display' => $filter->display($value)];
        }

        return $applied;
    }

    /**
     * The page keys filters, columnFilters, selectors and scopes, each only when it has entries.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $panel = [];
        $column = [];
        $selectors = [];

        foreach ($this->filters as $filter) {
            if ($filter instanceof Selector) {
                $selectors[] = $filter->toSchema();
            } elseif ($filter->placement() === 'column') {
                $column[] = $filter->toSchema();
            } else {
                // Hidden filters have no input, but their options are still checked.
                $filter->toSchema();
            }
        }

        foreach ($this->layout as $item) {
            $panel[] = $item === 'newline' ? ['type' => 'newline'] : $item->toSchema();
        }

        $hasPanelFilter = array_filter($this->layout, fn ($item) => $item !== 'newline') !== [];

        return ($hasPanelFilter ? ['filters' => ['columns' => $this->columns, 'items' => $panel]] : [])
            + ($column !== [] ? ['columnFilters' => $column] : [])
            + ($selectors !== [] ? ['selectors' => $selectors] : [])
            + ($this->scopes !== [] ? ['scopes' => array_values(array_map(fn (Scope $scope) => $scope->toSchema(), $this->scopes))] : []);
    }

    protected function simple(string $condition, string $key, string $label, ?string $column): Filter
    {
        return $this->panel(new Filter($key, $label, new FilterCondition($condition, $column ?? $key), self::defaultInput($condition)));
    }

    protected function panel(Filter $filter): Filter
    {
        $this->layout[] = $this->register($filter);

        return $filter;
    }

    /**
     * @template T of Filter|Selector
     *
     * @param  T  $filter
     * @return T
     */
    protected function register(Filter|Selector $filter): Filter|Selector
    {
        $key = self::checkKey($filter->key());

        if (isset($this->filters[$key])) {
            throw new InvalidArgumentException("篩選「{$key}」重複宣告了，同一個列表內篩選（含表頭篩選和 selector）的 key 不能重複。");
        }

        return $this->filters[$key] = $filter;
    }

    protected static function checkKey(string $key): string
    {
        if ($key === '' || strpbrk($key, '[]') !== false || in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
            throw new InvalidArgumentException("「{$key}」不能當篩選的 key：不能是空字串、不能含 [ 或 ]，也不能是 __proto__、constructor、prototype。");
        }

        return $key;
    }

    protected static function defaultInput(string $condition): FilterInput
    {
        $numbers = fn (int $to) => array_combine(range(1, $to), array_map('strval', range(1, $to)));

        return match ($condition) {
            'day' => FilterInput::choice('select', $numbers(31)),
            'month' => FilterInput::choice('select', $numbers(12)),
            'year' => FilterInput::text(numeric: true),
            'date' => FilterInput::date(),
            'in', 'notIn' => FilterInput::choice('multipleSelect', []),
            default => FilterInput::text(),
        };
    }
}
