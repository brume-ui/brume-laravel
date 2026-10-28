<?php

use Brume\Grid\Filters\FilterCondition;
use Brume\Grid\Filters\Filters;
use Brume\Grid\Grid;
use Brume\Grid\Query\FindInSet;
use Brume\Grid\Query\JsonContains;
use Brume\Tests\Support\Models\FilterItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which filter_items a grid lists for ?filter=$filter, numbered 1, 2, ... in creation order.
 *
 * @return list<int>
 */
function driverIds(Closure $declare, array $filter): array
{
    $grid = Grid::make(FilterItem::class);
    $grid->column('id');
    $declare($grid->filters());

    // Ids counted from the test's first row: MySQL keeps counting across rolled-back tests.
    $offset = FilterItem::query()->min('id') - 1;

    return array_map(
        fn (array $row) => $row['id'] - $offset,
        $grid->toSchema(Request::create('/items', 'GET', ['filter' => $filter]))['rows'],
    );
}

it('findInSet matches whole items only', function () {
    FilterItem::query()->create(['tags' => '1,12,3']);
    FilterItem::query()->create(['tags' => '2']);
    FilterItem::query()->create(['tags' => '12']);
    FilterItem::query()->create(['tags' => '5,2']);
    FilterItem::query()->create(['tags' => '7,2,9']);
    FilterItem::query()->create(['tags' => '22,x2']);
    $declare = fn (Filters $f) => $f->findInSet('tag', '標籤', 'tags');

    expect(driverIds($declare, ['tag' => '2']))->toBe([2, 4, 5])
        ->and(driverIds($declare, ['tag' => '12']))->toBe([1, 3])
        ->and(driverIds($declare, ['tag' => '1']))->toBe([1])
        ->and(driverIds($declare, ['tag' => '3']))->toBe([1]);
});

it('findInSet treats LIKE wildcards literally', function () {
    FilterItem::query()->create(['tags' => 'a_b,c']);
    FilterItem::query()->create(['tags' => 'axb,c']);
    FilterItem::query()->create(['tags' => '50%,c']);

    expect(driverIds(fn (Filters $f) => $f->findInSet('tag', '標籤', 'tags'), ['tag' => 'a_b']))->toBe([1])
        ->and(driverIds(fn (Filters $f) => $f->findInSet('tag', '標籤', 'tags'), ['tag' => '50%']))->toBe([3]);
});

it('builds findInSet from = and LIKE only', function () {
    $query = FilterItem::query();
    FindInSet::apply($query, 'tags', '2');
    $sql = strtolower($query->toSql());

    expect($sql)->not->toContain('find_in_set')
        ->and(substr_count($sql, ' like ? escape '))->toBe(3);
});

it('json contains falls back on sqlite', function () {
    FilterItem::query()->create(['labels' => ['red', 'blue']]);
    FilterItem::query()->create(['labels' => ['green']]);
    FilterItem::query()->create(['labels' => ['紅色', 'a/b']]);
    FilterItem::query()->create(['labels' => ['reddish']]);
    FilterItem::query()->create(['labels' => null]);
    // Raw UTF-8 and unescaped slashes, as JSON written outside Laravel's cast may be.
    DB::table('filter_items')->insert(['labels' => '["紅色","x/y"]']);
    $in = fn (Filters $f) => $f->in('label', '標籤', 'labels')->multipleSelect(['red' => '紅', 'green' => '綠', '紅色' => '紅色', 'a/b' => 'a/b', 'x/y' => 'x/y'])->json();
    $notIn = fn (Filters $f) => $f->notIn('label', '標籤', 'labels')->multipleSelect(['red' => '紅'])->json();

    $query = FilterItem::query();
    JsonContains::apply($query, 'labels', ['red']);

    expect(driverIds($in, ['label' => ['red']]))->toBe([1])
        ->and(driverIds($in, ['label' => ['red', 'green']]))->toBe([1, 2])
        ->and(driverIds($in, ['label' => ['紅色']]))->toBe([3, 6])
        ->and(driverIds($in, ['label' => ['a/b']]))->toBe([3])
        ->and(driverIds($in, ['label' => ['x/y']]))->toBe([6])
        ->and(driverIds($notIn, ['label' => ['red']]))->toBe([2, 3, 4, 6])
        ->and(strtolower($query->toSql()))->toContain(DB::getDriverName() === 'sqlite' ? ' like ? escape ' : 'json_contains');
});

it('filters a JSON path', function () {
    FilterItem::query()->create(['meta' => ['color' => 'red', 'size' => 3]]);
    FilterItem::query()->create(['meta' => ['color' => 'blue', 'size' => 10]]);

    expect(driverIds(fn (Filters $f) => $f->equal('color', '顏色', 'meta->color'), ['color' => 'blue']))->toBe([2])
        ->and(driverIds(fn (Filters $f) => $f->ilike('color', '顏色', 'meta->color'), ['color' => 'RE']))->toBe([1]);
});

it('notes the conditions that depend on the database', function (Closure $declare, bool $noted) {
    $filters = new Filters;
    $condition = $declare($filters)->condition();

    expect($condition)->toBeInstanceOf(FilterCondition::class)
        ->and($condition->driverNote() !== null)->toBe($noted);
})->with([
    'equal' => [fn (Filters $f) => $f->equal('a', 'A'), false],
    'gt' => [fn (Filters $f) => $f->gt('a', 'A'), false],
    'in' => [fn (Filters $f) => $f->in('a', 'A'), false],
    'between' => [fn (Filters $f) => $f->between('a', 'A'), false],
    'ilike' => [fn (Filters $f) => $f->ilike('a', 'A'), true],
    'like' => [fn (Filters $f) => $f->like('a', 'A'), true],
    'startWith' => [fn (Filters $f) => $f->startWith('a', 'A'), true],
    'findInSet' => [fn (Filters $f) => $f->findInSet('a', 'A'), true],
    'JSON path' => [fn (Filters $f) => $f->equal('a', 'A', 'meta->a'), true],
    'JSON contains' => [fn (Filters $f) => $f->in('a', 'A')->json(), true],
    'group with like' => [fn (Filters $f) => $f->group('a', 'A', ['equal', 'like']), true],
    'group of comparisons' => [fn (Filters $f) => $f->group('a', 'A'), false],
]);
