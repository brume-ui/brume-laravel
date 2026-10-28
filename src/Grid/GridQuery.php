<?php

namespace Brume\Grid;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The list state a request asks for (page, per_page, sort, q, scope, filter[*]), corrected to what
 * the grid allows.
 * Invalid values never fail the request: they fall back to the defaults (packages/schema/docs/url-params.md).
 */
final class GridQuery
{
    /**
     * @param  array{key: string, direction: 'asc'|'desc'}|null  $sort  only a sort the URL asked for
     * @param  array<string, mixed>  $filters  usable filter values (defaults included), by filter key
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?array $sort,
        public readonly ?string $q,
        public readonly array $filters = [],
        public readonly ?string $scope = null,
    ) {}

    public static function fromRequest(Request $request, Grid $grid): self
    {
        return new self(
            page: self::page($request->query('page')),
            perPage: self::perPage($request->query('per_page'), $grid),
            sort: self::sort($request->query('sort'), $grid),
            q: $grid->searchable() ? self::search($request->query('q')) : null,
            filters: $grid->filters()->values($request->query('filter'), useDefaults: ! $request->query->has('filter')),
            scope: $grid->filters()->scopeKey($request->query('scope')),
        );
    }

    /**
     * The `page.query` shape: what the list actually applied, which the frontend treats as the truth.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ($this->sort !== null ? ['sort' => $this->sort] : [])
            + ($this->q !== null ? ['q' => $this->q] : [])
            + ($this->scope !== null ? ['scope' => $this->scope] : [])
            + [
                'perPage' => $this->perPage,
                // An object even when empty (or keyed '0'): a PHP array could encode as a JSON list.
                'filters' => (object) $this->filters,
            ];
    }

    /**
     * Anything that is not a positive integer becomes page 1; past the end is clamped after counting.
     */
    protected static function page(mixed $page): int
    {
        if (! is_string($page) || ! preg_match('/^[1-9][0-9]*$/', $page)) {
            return 1;
        }

        // Guard against integer overflow in the OFFSET; anything this big clamps to the last page anyway.
        return min((int) substr($page, 0, 9), 999_999_999);
    }

    protected static function perPage(mixed $perPage, Grid $grid): int
    {
        $options = $grid->perPageChoices();

        if (is_string($perPage) && preg_match('/^[1-9][0-9]{0,8}$/', $perPage) && in_array((int) $perPage, $options, true)) {
            return (int) $perPage;
        }

        return $grid->defaultPerPage();
    }

    /**
     * @return array{key: string, direction: 'asc'|'desc'}|null
     */
    protected static function sort(mixed $sort, Grid $grid): ?array
    {
        if (! is_string($sort) || $sort === '') {
            return null;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = $direction === 'desc' ? substr($sort, 1) : $sort;

        return $grid->sortableColumn($key) !== null ? ['key' => $key, 'direction' => $direction] : null;
    }

    protected static function search(mixed $q): ?string
    {
        if (! is_string($q)) {
            return null;
        }

        // Str::trim also drops Unicode spaces, such as the full-width space a CJK keyboard types.
        $q = Str::trim($q);

        return $q === '' ? null : $q;
    }
}
