<?php

namespace Brume\Grid\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A named subset of the list (?scope=key), shown as pills after 「全部」. The closure adds where
 * clauses (it runs in a nested where).
 */
final class Scope
{
    /**
     * @param  Closure(Builder): mixed  $apply
     */
    public function __construct(
        protected string $key,
        protected string $label,
        protected Closure $apply,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @internal
     */
    public function apply(Builder $query): void
    {
        // Nested, so an orWhere in the closure cannot widen the rest of the query.
        $query->where(fn (Builder $nested) => ($this->apply)($nested));
    }

    /**
     * A ScopeDef.
     *
     * @internal
     *
     * @return array{key: string, label: string}
     */
    public function toSchema(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
