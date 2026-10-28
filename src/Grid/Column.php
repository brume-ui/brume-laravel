<?php

namespace Brume\Grid;

use BackedEnum;
use Brume\Grid\Concerns\HasDisplays;
use Brume\Grid\Concerns\HasEditing;
use Brume\Grid\Filters\Filter;
use Brume\Grid\Filters\Filters;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * The header text (label() is the label display).
     *
     * @internal
     */
    public function heading(): string
    {
        return $this->label;
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
     * and is not wrapped as the edit link. A primary column cannot also be edited inline
     * (editable(), switch(), switchGroup()).
     */
    public function primary(): static
    {
        if ($this->editor !== null) {
            throw new LogicException(static::primaryEditableMessage($this->key));
        }

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
     * The Excel cell value for a row: exportUsing()'s result when there is one; otherwise the
     * row's value (the display closure applied, a component's raw value), with label() options
     * turned into their labels, a switchGroup as the labels that are on, and a list joined
     * with 、. Scalars stay as they are; a numeric string of an attribute cast to a number (or of
     * a numeric() column) becomes a number. Without exportUsing(), an attribute in the model's
     * $hidden (through relations too) is refused.
     *
     * @internal
     */
    public function exportValue(mixed $row): string|int|float|bool|null
    {
        if ($this->exportUsing !== null) {
            return $this->exportScalar(($this->exportUsing)(data_get($row, $this->key), $row));
        }

        if (($hidden = $this->hiddenReads($row)) !== null) {
            throw new LogicException(sprintf(
                '欄位「%s」讀取的 %s 在 %s 的 $hidden 裡，匯出會把它寫進 Excel。請用 exportUsing() 指定要匯出的內容，或用 ->exportable(false) 不匯出這個欄位。',
                $this->key, implode('、', $hidden[0]), $hidden[1],
            ));
        }

        $value = $this->value($row);

        if (is_string($value) && is_numeric($value) && $this->display === null && $this->exportsNumber($row)) {
            return $value + 0;
        }

        if ($this->editor?->type() === 'switchGroup' && $this->display === null && is_array($value)) {
            $value = array_values(array_map(fn (string|int $key) => $this->switchKeys[$key], array_keys(array_filter($value))));
        }

        $labels = ($this->displaySchema['type'] ?? null) === 'label' ? (array) ($this->displaySchema['options'] ?? []) : [];

        return $this->exportScalar($value, fn (mixed $item) => is_scalar($item) && isset($labels[(string) $item]) ? $labels[(string) $item]['label'] : $item);
    }

    /**
     * $value as one cell: a list joined with 、 (a downloadable item as its URL), a date as
     * Y-m-d H:i:s. Another object (a model, ...) is refused: exportUsing() says what to write.
     */
    protected function exportScalar(mixed $value, ?Closure $item = null): string|int|float|bool|null
    {
        $item ??= fn (mixed $value) => $value;
        $value = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : static::normalize($value);

        if (is_iterable($value)) {
            $value = is_array($value) && isset($value['url']) && is_string($value['url']) ? $value['url'] : $value;
        }

        if (is_iterable($value)) {
            $parts = [];

            foreach ($value as $part) {
                $parts[] = (string) $this->exportScalar($part, $item);
            }

            return implode(__('brume::grid.separator'), $parts);
        }

        $value = $item($value);

        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException("欄位「{$this->key}」匯出的值是 ".get_debug_type($value).'，Excel 的儲存格只能放純量。請用 exportUsing() 轉成字串或數字。');
        }

        return $value;
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

        if ($this->display !== null) {
            $value = ($this->display)($value, $row);
        } elseif (! $this->displaysList()) {
            return static::normalize($value);
        }

        if ($this->displaysList()) {
            return $this->listDisplayValue($value);
        }

        $value = static::normalize($value);

        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException("欄位「{$this->key}」的 display() 必須回傳純量或 null（label、image、downloadable、table 顯示器可以回傳陣列），目前回傳 ".get_debug_type($value).'。請在 closure 內轉成字串或數字。');
        }

        return $value;
    }

    /**
     * This column's row._meta entries: href (link, button), content (expand, modal) and, for an
     * edited column with a display closure, raw (the value the editor starts from). A checkbox
     * column must be cast to an array (see assertCheckboxCast()).
     *
     * @internal
     *
     * @return array{href?: string, content?: array<string, mixed>, raw?: mixed}
     */
    public function rowMeta(mixed $row): array
    {
        $this->assertCheckboxCast($row);

        $meta = $this->displayMeta($row);

        if ($this->editor !== null && $this->display !== null) {
            $this->assertRawVisible($row);
            $meta['raw'] = $this->rawValue($row);
        }

        return $meta;
    }

    /**
     * Made JSON-safe: dates as ISO 8601, enums as their value, Stringables (other than models and
     * collections) as strings.
     *
     * @internal
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            // Models and collections are Stringable too (as JSON); they stay objects so the checks
            // on cell values can refuse them instead of sending every attribute as text.
            $value instanceof Stringable && ! $value instanceof Arrayable && ! $value instanceof Jsonable => (string) $value,
            default => $value,
        };
    }

    /**
     * _meta.raw sends the stored value even when display() masks it, so an attribute the model
     * hides ($hidden) must not be edited inline behind a display closure.
     */
    protected function assertRawVisible(mixed $row): void
    {
        if (($hidden = $this->hiddenReads($row)) !== null) {
            throw new LogicException(sprintf(
                '欄位「%s」的 %s 在 %s 的 $hidden 裡：行內編輯加上 display() 時，原始值會放在 _meta.raw 送到頁面。請不要行內編輯隱藏的欄位，或拿掉 display()。',
                $this->key, implode('、', $hidden[0]), $hidden[1],
            ));
        }
    }

    /**
     * The attributes this column reads that a model hides ($hidden), with that model's class, or
     * null. A switchGroup reads its keys on the row; a dotted key reads each segment on the model
     * reached so far (a relation's first model for a to-many relation).
     *
     * @return array{0: list<string>, 1: class-string<Model>}|null
     */
    protected function hiddenReads(mixed $row): ?array
    {
        if (! $row instanceof Model) {
            return null;
        }

        if ($this->editor?->type() === 'switchGroup') {
            $hidden = array_values(array_intersect(array_map('strval', array_keys($this->switchKeys)), $row->getHidden()));

            return $hidden !== [] ? [$hidden, $row::class] : null;
        }

        [$model, $attribute] = $this->attributeOwner($row);

        return $model !== null && in_array($attribute, $model->getHidden(), true) ? [[$attribute], $model::class] : null;
    }

    /**
     * The model the column's (dotted) key ends on and the attribute read there; the model is
     * null when the path leaves models (a null relation, an array). A hidden relation on the way
     * is reported as the attribute.
     *
     * @return array{0: Model|null, 1: string}
     */
    protected function attributeOwner(Model $row): array
    {
        $segments = explode('.', $this->key);
        $model = $row;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1 || in_array($segment, $model->getHidden(), true)) {
                return [$model, $segment];
            }

            $next = data_get($model, $segment);
            $next = $next instanceof EloquentCollection ? $next->first() : $next;

            if (! $next instanceof Model) {
                return [null, $segment];
            }

            $model = $next;
        }

        return [null, $this->key];
    }

    /**
     * Whether the column holds a number: numeric(), or an attribute cast to int, float or decimal.
     */
    protected function exportsNumber(mixed $row): bool
    {
        if ($this->numeric) {
            return true;
        }

        [$model, $attribute] = $row instanceof Model ? $this->attributeOwner($row) : [null, ''];

        return $model !== null && $model->hasCast($attribute, ['int', 'integer', 'float', 'double', 'real', 'decimal']);
    }

    /**
     * The value the editor starts from, JSON-safe.
     */
    protected function rawValue(mixed $row): mixed
    {
        return static::normalize($this->editedValue($row, data_get($row, $this->key)));
    }
}
