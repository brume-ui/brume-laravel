<?php

namespace Brume\Grid\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Does this JSON array column hold any of $values?" with whereJsonContains, except on SQLite,
 * whose Laravel grammar has no JSON contains: there each value is matched with Like as the JSON
 * text `"value"` (both the \u-escaped form Laravel's json cast writes and the raw UTF-8 form).
 *
 * The SQLite fallback only finds string items, and its LIKE ignores ASCII case.
 */
final class JsonContains
{
    /**
     * @param  list<string>  $values
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, string $column, array $values, bool $not = false): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $sqlite = $base->getConnection()->getDriverName() === 'sqlite';

        $any = function (EloquentBuilder|QueryBuilder $group) use ($column, $values, $sqlite) {
            foreach ($values as $value) {
                if (! $sqlite) {
                    $group->orWhereJsonContains($column, $value);

                    continue;
                }

                foreach (self::encodings($value) as $encoded) {
                    Like::contains($group, $column, $encoded, caseInsensitive: false, boolean: 'or');
                }
            }
        };

        $not ? $query->whereNot($any) : $query->where($any);
    }

    /**
     * @return list<string>
     */
    protected static function encodings(string $value): array
    {
        return array_values(array_unique([
            json_encode($value, JSON_THROW_ON_ERROR),
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }
}
