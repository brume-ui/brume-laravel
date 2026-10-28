<?php

namespace Brume\Grid\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * A row of pill options above the table (a SelectorDef), read from filter[key] like any filter:
 * a single choice filters with equal, several with in. Values outside the options are ignored.
 */
final class Selector
{
    protected FilterInput $input;

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public function __construct(
        protected string $key,
        protected string $label,
        array $options,
        protected bool $multiple,
        protected FilterCondition $condition,
    ) {
        $this->input = FilterInput::choice($multiple ? 'checkbox' : 'radio', $options);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Selectors have no default, so $useDefault changes nothing.
     *
     * @internal
     */
    public function parse(mixed $raw, bool $useDefault = false): mixed
    {
        return $this->input->parse($raw);
    }

    /**
     * @internal
     */
    public function apply(Builder $query, mixed $value): void
    {
        $this->condition->apply($query, $value);
    }

    /**
     * @internal
     */
    public function display(mixed $value): string
    {
        return $this->input->display($value);
    }

    /**
     * A SelectorDef.
     *
     * @internal
     *
     * @return array{key: string, label: string, multiple: bool, options: list<array{value: string, label: string}>}
     */
    public function toSchema(): array
    {
        $this->input->validate($this->label);

        return ['key' => $this->key, 'label' => $this->label, 'multiple' => $this->multiple, 'options' => $this->input->choices()];
    }
}
