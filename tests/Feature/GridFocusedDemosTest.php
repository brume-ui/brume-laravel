<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\Route;
use OpenSpout\Reader\XLSX\Reader;
use Workbench\App\Http\Controllers\GridShowcaseController;
use Workbench\App\Models\ShowcaseItem;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('grid-showcase', GridShowcaseController::class)->names('brume.grid-showcase');
    });
    $owner = brumeUser(['username' => 'focused-owner', 'name' => '林負責']);
    foreach ([['Ada_50%', 10, 'kitchen', '2026-01-05 09:30:59'], ['ada king', 20, 'stationery', '2026-02-14 09:31:00'], ['Grace', 30, 'kitchen', '2026-02-28 23:00:00'], ['Linus', 40, 'textile', '2025-12-31 18:00:00']] as $i => [$name, $stock, $category, $created]) {
        ShowcaseItem::factory()->create(['name' => $name, 'sku' => 'FD-00'.($i + 1), 'stock' => $stock, 'status' => $i % 2 === 0 ? 'paid' : 'pending', 'warehouse' => $i === 0 ? 'taipei' : 'kaohsiung', 'description' => $i === 0 ? 'Coffee' : 'Tea', 'progress' => $stock, 'rating' => $i + 1, 'views' => $stock, 'price' => ($i + 1) * 100, 'owner_id' => $i === 0 ? $owner->id : null, 'category' => $category, 'created_at' => $created, 'note' => $i === 0 ? '1,12,3' : '12,2', 'tags' => $i === 0 ? ['gift'] : ['eco'], 'meta' => ['color' => $i === 0 ? 'teal' : 'sand']]);
    }
});

it('runs each focused public operator against real rows', function (string $operator, mixed $value, array $expected) {
    $query = ['example' => 'grid.operator.'.$operator, 'filter' => ['demo' => $value], 'sort' => 'sku'];
    $page = $this->get('/admin/grid-showcase?'.http_build_query($query))->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['rows'], 'sku'))->toBe($expected);
})->with([
    'equal' => ['equal', 'kitchen', ['FD-001', 'FD-003']],
    'equal set' => ['equal', ['kitchen', 'textile'], ['FD-001', 'FD-003', 'FD-004']],
    'notEqual' => ['notEqual', ['kitchen'], ['FD-002', 'FD-004']],
    'like' => ['like', 'king', ['FD-002']],
    'ilike' => ['ilike', 'ADA', ['FD-001', 'FD-002']],
    'startWith literal' => ['startWith', 'Ada_', ['FD-001']],
    'endWith literal' => ['endWith', '50%', ['FD-001']],
    'gt' => ['gt', '20', ['FD-003', 'FD-004']],
    'lt' => ['lt', '20', ['FD-001']],
    'ngt' => ['ngt', '20', ['FD-001', 'FD-002']],
    'nlt' => ['nlt', '20', ['FD-002', 'FD-003', 'FD-004']],
    'in' => ['in', ['kitchen', 'textile'], ['FD-001', 'FD-003', 'FD-004']],
    'notIn' => ['notIn', ['kitchen', 'textile'], ['FD-002']],
    'findInSet whole item' => ['findInSet', '1', ['FD-001']],
    'date' => ['date', '2026-02-14', ['FD-002']],
    'day' => ['day', '28', ['FD-003']],
    'month' => ['month', '2', ['FD-002', 'FD-003']],
    'year' => ['year', '2025', ['FD-004']],
    'between' => ['between', ['start' => '10', 'end' => '20'], ['FD-001', 'FD-002']],
    'whereBetween' => ['whereBetween', ['end' => '20'], ['FD-001', 'FD-002']],
    'where' => ['where', '20', ['FD-002']],
    'group' => ['group', ['op' => 'gt', 'value' => '20'], ['FD-003', 'FD-004']],
    'hidden' => ['hidden', 'kitchen', ['FD-001', 'FD-003']],
]);

function focusedPage(string $example, array $query = []): array
{
    return test()->get('/admin/grid-showcase?'.http_build_query(['example' => 'grid.query.'.$example, ...$query]))->assertOk()->viewData('page')['props']['envelope']['page'];
}

it('runs JSON membership paths relations and default clearing in focused workbench declarations', function () {
    expect(array_column(focusedPage('json', ['filter' => ['demo' => ['gift']]])['rows'], 'sku'))->toBe(['FD-001'])
        ->and(array_column(focusedPage('json', ['filter' => ['exclude' => ['gift']]])['rows'], 'sku'))->toBe(['FD-002', 'FD-003', 'FD-004'])
        ->and(array_column(focusedPage('json-path', ['filter' => ['demo' => 'teal']])['rows'], 'sku'))->toBe(['FD-001'])
        ->and(array_column(focusedPage('relation', ['filter' => ['demo' => '林']])['rows'], 'sku'))->toBe(['FD-001'])
        ->and(array_column(focusedPage('relation', ['q' => 'FD-002', 'filter' => ['demo' => '林']])['rows'], 'sku'))->toBe([])
        ->and(array_column(focusedPage('defaults')['rows'], 'sku'))->toBe(['FD-001', 'FD-003'])
        ->and(focusedPage('defaults', ['filter' => ''])['pagination']['total'])->toBe(4);
});

it('keeps callback OR conditions scoped by the root search and ignores invalid group operators', function () {
    ShowcaseItem::query()->where('sku', 'FD-004')->update(['stock' => -1]);
    foreach (['where' => '20', 'whereBetween' => ['start' => '10', 'end' => '20']] as $operator => $value) {
        $page = $this->get('/admin/grid-showcase?'.http_build_query(['example' => 'grid.operator.'.$operator, 'q' => 'FD-002', 'filter' => ['demo' => $value]]))->assertOk()->viewData('page')['props']['envelope']['page'];
        expect(array_column($page['rows'], 'sku'))->toBe(['FD-002']);
    }
    $page = $this->get('/admin/grid-showcase?'.http_build_query(['example' => 'grid.operator.group', 'filter' => ['demo' => ['op' => 'between', 'value' => '20']]]))->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page['appliedFilters'])->toBe([])->and($page['pagination']['total'])->toBe(4);
});

it('includes the entire ending minute or day and preserves single ended ranges', function () {
    expect(array_column(focusedPage('driver-notes', ['filter' => ['minute' => ['start' => '2026-01-05T09:30', 'end' => '2026-01-05T09:30']]])['rows'], 'sku'))->toBe(['FD-001'])
        ->and(array_column(focusedPage('driver-notes', ['filter' => ['day' => ['end' => '2026-01-05']]])['rows'], 'sku'))->toBe(['FD-001', 'FD-004']);
});

it('runs custom sorting with default and illegal sorts and keeps the real numeric row identity', function () {
    expect(array_column(focusedPage('sort-custom', ['sort' => 'price'])['rows'], 'sku'))->toBe(['FD-004', 'FD-003', 'FD-002', 'FD-001'])
        ->and(array_column(focusedPage('sort-custom', ['sort' => 'secret_note'])['rows'], 'sku'))->toBe(['FD-001', 'FD-002', 'FD-003', 'FD-004']);
    $page = focusedPage('row-id');
    expect($page['rows'][0]['id'])->toBe(ShowcaseItem::query()->where('sku', 'FD-001')->firstOrFail()->id);
});

it('exports a real focused XLSX with labels raw numeric values and explicit exportUsing without secrets', function () {
    $response = $this->get('/admin/grid-showcase/_brume/export?example=grid.query.export-values&export_scope=all')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'focused-xlsx-');
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    try {
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        expect($rows[0])->not->toContain('不匯出的秘密')
            ->and($rows[1][2])->toBe('廚房')
            ->and($rows[1][5])->toBe(100)
            ->and($rows[1][6])->toBe('100.00')
            ->and($rows[1][7])->toBe('FD-001：1,12,3');
    } finally {
        $reader->close();
        unlink($path);
    }
});

it('maps every public Column filter to actual independent result rows', function (string $key, mixed $value, array $expected) {
    $page = focusedPage('column-operators', ['filter' => [$key => $value]]);
    $skus = array_column($page['rows'], 'sku');
    sort($skus);
    expect($skus)->toBe($expected);
})->with([
    'between' => ['price', ['start' => '100', 'end' => '200'], ['FD-001', 'FD-002']],
    'checkbox' => ['status', ['paid'], ['FD-001', 'FD-003']],
    'equal' => ['category', 'kitchen', ['FD-001', 'FD-003']],
    'gt' => ['stock', '20', ['FD-003', 'FD-004']],
    'in' => ['warehouse', ['taipei'], ['FD-001']],
    'input aliases like' => ['note', '3', ['FD-001']],
    'like' => ['description', 'Coffee', ['FD-001']],
    'lt' => ['rating', '3', ['FD-001', 'FD-002']],
    'ngt' => ['progress', '20', ['FD-001', 'FD-002']],
    'nlt' => ['views', '30', ['FD-003', 'FD-004']],
    'startWith' => ['sku', 'FD-001', ['FD-001']],
]);
