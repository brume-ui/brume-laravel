<?php

namespace Brume\Grid\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Literal LIKE matching that reads the same on SQLite, MySQL and PostgreSQL: ANSI
 * `LIKE ? ESCAPE '!'`, with `LOWER()` on both sides when case does not matter. The term's
 * `!`, `%` and `_` are escaped, so "50%" finds "50%off" and never "500ff".
 */
final class Like
{
    public const ESCAPE = '!';

    public static function contains(EloquentBuilder|QueryBuilder $query, string $column, string $term, bool $caseInsensitive = true, string $boolean = 'and'): void
    {
        self::where($query, $column, '%'.self::escape($term).'%', $caseInsensitive, $boolean);
    }

    public static function startsWith(EloquentBuilder|QueryBuilder $query, string $column, string $term, bool $caseInsensitive = true, string $boolean = 'and'): void
    {
        self::where($query, $column, self::escape($term).'%', $caseInsensitive, $boolean);
    }

    public static function endsWith(EloquentBuilder|QueryBuilder $query, string $column, string $term, bool $caseInsensitive = true, string $boolean = 'and'): void
    {
        self::where($query, $column, '%'.self::escape($term), $caseInsensitive, $boolean);
    }

    /**
     * Escape the LIKE wildcards and the escape character itself.
     */
    public static function escape(string $term): string
    {
        return strtr($term, [
            self::ESCAPE => self::ESCAPE.self::ESCAPE,
            '%' => self::ESCAPE.'%',
            '_' => self::ESCAPE.'_',
        ]);
    }

    protected static function where(EloquentBuilder|QueryBuilder $query, string $column, string $pattern, bool $caseInsensitive, string $boolean): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $wrapped = $base->getGrammar()->wrap($column);
        $escape = "escape '".self::ESCAPE."'";

        $sql = $caseInsensitive
            ? "lower({$wrapped}) like lower(?) {$escape}"
            : "{$wrapped} like ? {$escape}";

        $query->whereRaw($sql, [$pattern], $boolean);
    }
}
