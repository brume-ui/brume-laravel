<?php

namespace Brume\Grid\Filters;

use Brume\Grid\Options\RemoteOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One filter: a condition, the input it is edited with, and where it shows (the panel, a column
 * header, or nowhere for hidden()). Read from filter[key] in the URL.
 */
class Filter
{
    /** @var 'panel'|'column'|'hidden' */
    protected string $placement = 'panel';

    protected int $span = 1;

    protected mixed $default = null;

    public function __construct(
        protected string $key,
        protected string $label,
        protected FilterCondition $condition,
        protected FilterInput $input,
    ) {}

    public function text(?string $placeholder = null): static
    {
        return $this->input('text', FilterInput::text($placeholder));
    }

    /**
     * Numbers only, on a text, range or group input.
     */
    public function numeric(): static
    {
        return $this->number();
    }

    /**
     * Numbers only: a text input gets a numeric keyboard, a range or group compares numbers.
     */
    public function number(): static
    {
        $this->input = $this->input->asNumber()
            ?? throw new InvalidArgumentException("篩選「{$this->label}」的輸入是 {$this->input->type()}，number() 只能用在文字、區間或條件群組輸入。");

        return $this;
    }

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public function select(array $options): static
    {
        return $this->input('select', FilterInput::choice('select', $options));
    }

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public function multipleSelect(array $options): static
    {
        return $this->input('multipleSelect', FilterInput::choice('multipleSelect', $options));
    }

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public function radio(array $options): static
    {
        return $this->input('radio', FilterInput::choice('radio', $options));
    }

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public function checkbox(array $options): static
    {
        return $this->input('checkbox', FilterInput::choice('checkbox', $options));
    }

    /**
     * A date picker; on between() a date or datetime range.
     */
    public function date(bool $withTime = false): static
    {
        if ($this->isRange()) {
            $this->input = FilterInput::range($withTime ? 'datetime' : 'date');

            return $this;
        }

        return $this->input('date', FilterInput::date($withTime));
    }

    /**
     * Options searched on the server (SelectTable): the model's $labelColumn, matched by
     * $searchColumns (default the label), with $valueColumn (default the key) as the value.
     *
     * @param  class-string<Model>|Builder  $model
     * @param  list<string>  $searchColumns
     */
    public function remoteSelect(string|Builder $model, string $labelColumn, ?string $valueColumn = null, array $searchColumns = []): static
    {
        return $this->input('select', FilterInput::remote(RemoteOptions::make($model, $labelColumn, $valueColumn, $searchColumns), multiple: false));
    }

    /**
     * remoteSelect() choosing several values (MultipleSelectTable).
     *
     * @param  class-string<Model>|Builder  $model
     * @param  list<string>  $searchColumns
     */
    public function remoteMultipleSelect(string|Builder $model, string $labelColumn, ?string $valueColumn = null, array $searchColumns = []): static
    {
        return $this->input('multipleSelect', FilterInput::remote(RemoteOptions::make($model, $labelColumn, $valueColumn, $searchColumns), multiple: true));
    }

    /**
     * Columns the filter takes in the panel: 1 or 2.
     */
    public function span(int $n): static
    {
        if (! in_array($n, [1, 2], true)) {
            throw new InvalidArgumentException("篩選「{$this->label}」的 span() 只能是 1 或 2，目前是 {$n}。");
        }

        $this->span = $n;

        return $this;
    }

    /**
     * The value used while the URL has none for this filter, in the URL's shape: a string, a list,
     * ['start' => ..., 'end' => ...] or ['op' => ..., 'value' => ...].
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * The column is a JSON array: in / notIn match rows whose array contains a chosen value.
     */
    public function json(): static
    {
        $this->condition->useJson();

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function condition(): FilterCondition
    {
        return $this->condition;
    }

    /**
     * @internal
     */
    public function filterInput(): FilterInput
    {
        return $this->input;
    }

    /**
     * @internal
     *
     * @param  'panel'|'column'|'hidden'  $placement
     */
    public function placeIn(string $placement): static
    {
        $this->placement = $placement;

        return $this;
    }

    /**
     * @internal
     */
    public function placement(): string
    {
        return $this->placement;
    }

    /**
     * The value in filter[key], else the default; null when neither is usable.
     *
     * @internal
     */
    public function parse(mixed $raw): mixed
    {
        $value = $this->input->parse($raw ?? $this->default);

        // A set item never holds the separator.
        if ($this->condition->type() === 'findInSet' && is_string($value) && str_contains($value, ',')) {
            return null;
        }

        return $value;
    }

    /**
     * @internal
     */
    public function apply(Builder $query, mixed $value): void
    {
        $this->condition->apply($query, $value, $this->input->of());
    }

    /**
     * @internal
     */
    public function display(mixed $value): string
    {
        $remote = $this->input->remoteOptions();

        return $this->input->display($value, $remote?->labels((array) $value) ?? []);
    }

    /**
     * A FilterDef.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $this->input->validate($this->label);

        return [
            'key' => $this->key,
            'label' => $this->label,
            'input' => $this->input->toSchema(),
            'placement' => $this->placement,
        ] + ($this->span !== 1 ? ['span' => $this->span] : []);
    }

    protected function isRange(): bool
    {
        return in_array($this->condition->type(), ['between', 'whereBetween'], true);
    }

    /**
     * Replace the input, if the condition takes that kind of value.
     */
    protected function input(string $method, FilterInput $input): static
    {
        $type = $this->condition->type();

        $fits = match (true) {
            $this->isRange(), $type === 'group' => false,
            $input->isList() => in_array($type, ['equal', 'notEqual', 'in', 'notIn', 'where'], true),
            default => true,
        };

        if (! $fits) {
            throw new InvalidArgumentException("篩選「{$this->label}」是 {$type} 條件，不能用 {$method}() 輸入。");
        }

        $this->input = $input;

        return $this;
    }
}
