<?php

namespace Brume\Grid;

use BackedEnum;
use Brume\Grid\Concerns\HasDisplays;
use Brume\Grid\Concerns\HasEditing;
use Brume\Grid\Filters\Filter;
use Brume\Grid\Filters\Filters;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Stringable;

class Column
{
    use HasDisplays;
    use HasEditing;

    protected bool $numeric = false;

    protected ?Closure $display = null;

    protected bool $sortable = false;

    /** fn (Builder $query, string $direction): void; null sorts by the column key. */
    protected ?Closure $sorter = null;

    protected ?string $width = null;

    protected bool $hideable = true;

    protected bool $hiddenByDefault = false;

    protected bool $primary = false;

    protected ?Filters $filters = null;

    protected ?Filter $filter = null;

    protected bool $exportable = true;

    /** fn ($value, Model $row): mixed, the Excel cell value. */
    protected ?Closure $exportUsing = null;

    public function __construct(
        protected string $key,
        protected string $label,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public static function defaultLabel(string $key): string
    {
        return Str::headline($key);
    }

    public function numeric(): static
    {
        $this->numeric = true;

        // A header filter declared before numeric() takes numbers too.
        if ($this->filter?->filterInput()->asNumber() !== null) {
            $this->filter->number();
        }

        return $this;
    }

    /**
     * Transform the value on the server: fn ($value, $row) => scalar|null, or a list for the
     * label(), image(), downloadable() and table() displays.
     */
    public function display(Closure $fn): static
    {
        $this->display = $fn;

        return $this;
    }

    /**
     * Let the header sort the list by this column: ?sort=key ascending, ?sort=-key descending.
     * A dotted key (a relation) needs the closure: fn (Builder $query, string $direction).
     */
    public function sortable(?Closure $apply = null): static
    {
        if ($apply === null && str_contains($this->key, '.')) {
            throw new InvalidArgumentException("欄位「{$this->key}」是關聯欄位，sortable() 需要傳入排序的 closure，例如 fn (\$query, string \$direction) => \$query->orderBy(...)。");
        }

        $this->sortable = true;
        $this->sorter = $apply;

        return $this;
    }

    /**
     * A CSS width for the column, e.g. '12rem' or '20%'.
     */
    public function width(string $css): static
    {
        $this->width = $css;

        return $this;
    }

    /**
     * Whether people can hide the column from the 顯示欄位 menu (they can by default).
     */
    public function hideable(bool $on = true): static
    {
        $this->hideable = $on;

        return $this;
    }

    /**
     * Start hidden; people can show it from the 顯示欄位 menu.
     */
    public function hidden(): static
    {
        $this->hiddenByDefault = true;

        return $this;
    }

    /**
     * The column that opens the row (its edit entry). Only a plain-text column becomes the edit
     * link: with another display (label(), link(), a component, ...) the cell keeps that display
     * and is not wrapped as the edit link.
     */
    public function primary(): static
    {
        $this->primary = true;

        return $this;
    }

    /**
     * A filter in the column header, keyed by the column key (filter[key]). $type is one of
     * between, checkbox, equal, gt, in, input (contains), like, lt, ngt, nlt, startWith;
     * in and checkbox need $options (value => label), which turn equal into a select. On a
     * numeric() column the input takes numbers.
     *
     * @param  array<int|string, string>  $options
     */
    public function filter(string $type, array $options = []): static
    {
        if ($this->filters === null) {
            throw new LogicException("欄位「{$this->key}」不在 Grid 裡，filter() 要用在 \$grid->column() 回傳的欄位上。");
        }

        $this->filter = $this->filters->forColumn($this->key, $this->label, $type, $options, $this->numeric);

        return $this;
    }

    /**
     * The Excel cell value: fn ($value, Model $row) => mixed.
     */
    public function exportUsing(Closure $fn): static
    {
        $this->exportUsing = $fn;

        return $this;
    }

    /**
     * Whether the column goes into the Excel export (it does by default).
     */
    public function exportable(bool $on = true): static
    {
        $this->exportable = $on;

        return $this;
    }

    /**
     * @internal
     */
    public function isExportable(): bool
    {
        return $this->exportable;
    }

    /**
     * @internal
     */
    public function exportCallback(): ?Closure
    {
        return $this->exportUsing;
    }

    /**
     * @internal
     */
    public function withFilters(Filters $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @internal
     */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /**
     * @internal
     *
     * @param  'asc'|'desc'  $direction
     */
    public function applySort(Builder $query, string $direction): void
    {
        if ($this->sorter !== null) {
            ($this->sorter)($query, $direction);

            return;
        }

        // Unqualified on purpose: the key may be a select alias such as a withCount() column.
        $query->orderBy($this->key, $direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ]
            + ($this->numeric ? ['numeric' => true] : [])
            + ($this->width !== null ? ['width' => $this->width] : [])
            + ($this->displaySchema !== null ? ['display' => $this->displaySchema] : [])
            + ($this->editor !== null ? ['editor' => $this->editor->toSchema()] : [])
            + ($this->sortable ? ['sortable' => true] : [])
            + ($this->filter !== null ? ['filter' => $this->filter->key()] : [])
            // The schema's default is true, so only the exception is written out.
            + ($this->hideable ? [] : ['hideable' => false])
            + ($this->hiddenByDefault ? ['hiddenByDefault' => true] : [])
            + ($this->primary ? ['primary' => true] : []);
    }

    /**
     * The cell value for a model: data_get by the (dotted) key (a switch as a boolean, a
     * switchGroup as { attribute: bool }), then the display closure, made JSON-safe.
     */
    public function value(mixed $row): mixed
    {
        // The closure gets the attribute as the model has it (a Carbon date, an enum, ...).
        $value = $this->editedValue($row, data_get($row, $this->key));

        if ($this->display === null) {
            return $this->shapeDisplayed(static::normalize($value));
        }

        $value = ($this->display)($value, $row);

        if ($this->displaysList() && is_iterable($value)) {
            return $this->tableColumns !== []
                ? $this->shapeDisplayed($value)
                : array_map(static::normalize(...), array_values(collect($value)->all()));
        }

        $value = static::normalize($value);

        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException("欄位「{$this->key}」的 display() 必須回傳純量或 null（label、image、downloadable、table 顯示器可以回傳陣列），目前回傳 ".get_debug_type($value).'。請在 closure 內轉成字串或數字。');
        }

        return $value;
    }

    /**
     * This column's row._meta entries: href (link, button), content (expand, modal) and, for an
     * edited column with a display closure, raw (the value the editor starts from).
     *
     * @internal
     *
     * @return array{href?: string, content?: array<string, mixed>, raw?: mixed}
     */
    public function rowMeta(mixed $row): array
    {
        $meta = $this->displayMeta($row);

        if ($this->editor !== null && $this->display !== null) {
            $meta['raw'] = $this->rawValue($row);
        }

        return $meta;
    }

    /**
     * Made JSON-safe: dates as ISO 8601, enums as their value, Stringables as strings.
     *
     * @internal
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    /**
     * The value the editor starts from, JSON-safe.
     */
    protected function rawValue(mixed $row): mixed
    {
        return static::normalize($this->editedValue($row, data_get($row, $this->key)));
    }
}
