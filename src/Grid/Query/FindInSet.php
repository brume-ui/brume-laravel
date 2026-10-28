<?php

namespace Brume\Grid\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Is $value one item of this comma-separated column?", written portably instead of MySQL's
 * FIND_IN_SET(): `col = v OR col LIKE 'v,%' OR col LIKE '%,v' OR col LIKE '%,v,%'`, with the
 * value's LIKE wildcards escaped by Like. So '1,12,3' matches 12 but not 2.
 *
 * Items must be stored without spaces around the commas. No index is used. Case follows the
 * database's `=` and LIKE (see FilterCondition::driverNote()).
 */
final class FindInSet
{
    public static function apply(EloquentBuilder|QueryBuilder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $query->where(function (EloquentBuilder|QueryBuilder $set) use ($column, $value) {
            $set->where($column, $value);
            Like::startsWith($set, $column, $value.',', caseInsensitive: false, boolean: 'or');
            Like::endsWith($set, $column, ','.$value, caseInsensitive: false, boolean: 'or');
            Like::contains($set, $column, ','.$value.',', caseInsensitive: false, boolean: 'or');
        }, boolean: $boolean);
    }
}
