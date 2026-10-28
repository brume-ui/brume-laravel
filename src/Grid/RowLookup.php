<?php

namespace Brume\Grid;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The rows the `_brume` endpoints (actions, inline editing) may touch: the grid's query, with its
 * conditions and global scopes but without the list's search, filters, sort or paging. An id the
 * list could never show is not found.
 *
 * @internal
 */
final class RowLookup
{
    public function __construct(private Builder $query) {}

    /**
     * $value as a key of the grid's model, or null when it cannot be one.
     *
     * An integer key takes an int ≥ 0 or a string of digits only ("1abc", "", " 1" and "-1" are
     * refused); leading zeros are dropped, so "01" is 1. Any other key takes a non-blank string, or
     * an int as a string.
     */
    public function normalizeId(mixed $value): int|string|null
    {
        if (in_array($this->query->getModel()->getKeyType(), ['int', 'integer'], true)) {
            if (is_int($value)) {
                return $value >= 0 ? $value : null;
            }

            if (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
                return null;
            }

            $digits = ltrim($value, '0');
            $digits = $digits === '' ? '0' : $digits;

            // Beyond PHP_INT_MAX the cast would saturate into another id.
            return (string) (int) $digits === $digits ? (int) $digits : null;
        }

        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) && trim($value) !== '' => $value,
            default => null,
        };
    }

    /**
     * The rows with these normalized, distinct ids, each once (a join that repeats a row does not
     * count twice), or null when any of them is not in the grid's query.
     *
     * @param  list<int|string>  $ids
     */
    public function find(array $ids): ?EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        // unique() without a key keeps one model per primary key.
        $models = (clone $this->query)->whereKey($ids)->get()->unique();
        $found = array_map(fn (mixed $key) => $this->normalizeId($key), $models->modelKeys());

        return array_diff($ids, $found) === [] ? $models : null;
    }

    public function query(): Builder
    {
        return clone $this->query;
    }
}
