<?php

use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\SortCode;
use Brume\Tests\Support\Models\SortItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * /admin/sorts: shop 1's items on a Route::brumeGrid page behind brume.auth, ordered by `sort`
 * (orderable('sort'), unless $orderable is false); $declare configures the grid further.
 */
function sortRoutes(?Closure $declare = null, bool $orderable = true, string $uri = 'sorts'): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid($uri, function () use ($declare, $orderable) {
            $grid = Grid::make(SortItem::query()->where('shop_id', 1))->title('Sorts')->quickSearch('name');
            $grid->column('name', '名稱')->primary();
            $grid->column('sort', '排序');
            if ($orderable) {
                $grid->orderable('sort');
            }
            $declare?->__invoke($grid);

            return $grid;
        }));
}

/**
 * Shop 1's items named after the keys of $sorts, created in that order with those sort values.
 *
 * @param  array<string, int|null>  $sorts
 * @return array<string, SortItem>
 */
function sortItems(array $sorts): array
{
    $items = [];
    foreach ($sorts as $name => $sort) {
        $items[$name] = SortItem::query()->create(['shop_id' => 1, 'name' => $name, 'sort' => $sort]);
    }

    return $items;
}

/**
 * Shop 1's names by sort value (then id), and the values themselves.
 *
 * @return array<string, int|null>
 */
function sortValues(): array
{
    return SortItem::query()->where('shop_id', 1)->orderByRaw('sort is null')->orderBy('sort')->orderBy('id')
        ->pluck('sort', 'name')->all();
}

function postReorder(array $body, string $uri = '/admin/sorts/_brume/reorder'): TestResponse
{
    return test()->postJson($uri, $body);
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
});

test('orderable_schema_has_ordering_and_reorder_endpoint', function () {
    sortItems(['A' => 1, 'B' => 2]);
    sortRoutes();

    $envelope = $this->get('/admin/sorts')->assertOk()->viewData('page')['props']['envelope'];

    expect($envelope['page']['ordering'])->toBe(['mode' => 'list', 'column' => 'sort', 'direction' => 'asc'])
        ->and($envelope['page']['endpoints']['reorder'])->toBe('/admin/sorts/_brume/reorder')
        ->and(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
});

test('default_sort_puts_nulls_last', function () {
    sortItems(['A' => null, 'B' => 2, 'C' => 1, 'D' => null]);
    SortItem::query()->create(['shop_id' => 2, 'name' => 'E', 'sort' => 0]);
    sortRoutes();

    $names = fn (string $uri) => array_column($this->get($uri)->assertOk()->viewData('page')['props']['envelope']['page']['rows'], 'name');

    expect($names('/admin/sorts'))->toBe(['C', 'B', 'A', 'D']);

    // An explicit defaultSort() wins over the ordering.
    sortRoutes(fn (Grid $grid) => $grid->defaultSort('name', 'desc'), uri: 'named');

    expect($names('/admin/named'))->toBe(['D', 'C', 'B', 'A']);
});

test('move_after_renumbers_the_whole_scope_from_one', function () {
    $items = sortItems(['A' => 10, 'B' => 20, 'C' => 30, 'D' => null]);
    $other = SortItem::query()->create(['shop_id' => 2, 'name' => 'E', 'sort' => 5]);
    sortRoutes();

    $response = postReorder(['id' => $items['A']->id, 'after' => $items['C']->id])->assertOk();

    expect(JsonSchema::errors($response->json(), 'ReorderResponse'))->toBe([])
        ->and($response->json())->toBe(['undo' => ['id' => $items['A']->id, 'before' => $items['B']->id]])
        ->and(sortValues())->toBe(['B' => 1, 'C' => 2, 'A' => 3, 'D' => 4])
        ->and($other->fresh()->sort)->toBe(5);
});

test('move_before_first_and_to_end', function () {
    $items = sortItems(['A' => 1, 'B' => 2, 'C' => 3]);
    sortRoutes();

    postReorder(['id' => $items['C']->id, 'before' => $items['A']->id])->assertOk()
        ->assertExactJson(['undo' => ['id' => $items['C']->id, 'after' => $items['B']->id]]);
    expect(sortValues())->toBe(['C' => 1, 'A' => 2, 'B' => 3]);

    // Neither before nor after: to the end.
    postReorder(['id' => $items['C']->id])->assertOk()
        ->assertExactJson(['undo' => ['id' => $items['C']->id, 'before' => $items['A']->id]]);
    expect(sortValues())->toBe(['A' => 1, 'B' => 2, 'C' => 3]);
});

test('desc_direction_numbers_first_item_highest', function () {
    $items = sortItems(['A' => 3, 'B' => 2, 'C' => 1, 'D' => null]);
    sortRoutes(fn (Grid $grid) => $grid->orderable('sort', 'desc'), orderable: false);

    $page = $this->get('/admin/sorts')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['rows'], 'name'))->toBe(['A', 'B', 'C', 'D'])
        ->and($page['ordering']['direction'])->toBe('desc');

    postReorder(['id' => $items['D']->id, 'before' => $items['A']->id])->assertOk();

    expect(SortItem::query()->orderBy('id')->pluck('sort', 'name')->all())->toBe(['A' => 3, 'B' => 2, 'C' => 1, 'D' => 4]);
});

test('only_changed_rows_are_updated_and_updated_at_is_untouched', function () {
    $items = sortItems(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
    $stamps = SortItem::query()->orderBy('id')->pluck('updated_at', 'name')->map(fn ($at) => (string) $at)->all();
    sortRoutes();
    $this->travel(2)->days();

    $updating = 0;
    SortItem::updating(function () use (&$updating) {
        $updating++;
    });
    $updates = [];
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
            $updates[] = $query->bindings;
        }
    });

    postReorder(['id' => $items['B']->id, 'after' => $items['C']->id])->assertOk();

    expect(sortValues())->toBe(['A' => 1, 'C' => 2, 'B' => 3, 'D' => 4])
        ->and($updates)->toHaveCount(2)
        ->and($updating)->toBe(0)
        ->and(SortItem::query()->orderBy('id')->pluck('updated_at', 'name')->map(fn ($at) => (string) $at)->all())->toBe($stamps);
});

test('undo_payload_restores_the_previous_order', function () {
    $items = sortItems(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
    sortRoutes();

    $undo = postReorder(['id' => $items['A']->id, 'after' => $items['C']->id])->assertOk()->json('undo');
    expect(sortValues())->toBe(['B' => 1, 'C' => 2, 'A' => 3, 'D' => 4]);
    postReorder($undo)->assertOk();
    expect(sortValues())->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);

    // The last item comes back after its old neighbor.
    $undo = postReorder(['id' => $items['D']->id, 'before' => $items['A']->id])->assertOk()->json('undo');
    expect($undo)->toBe(['id' => $items['D']->id, 'after' => $items['C']->id]);
    postReorder($undo)->assertOk();
    expect(sortValues())->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);

    // A scope of one has nowhere else to go back to.
    SortItem::query()->whereKeyNot($items['A']->id)->delete();
    postReorder(['id' => $items['A']->id])->assertOk()->assertExactJson([]);
});

test('stale_anchor_returns_conflict_without_writing', function () {
    $items = sortItems(['A' => null, 'B' => null, 'C' => null]);
    sortRoutes();
    $items['B']->delete();

    $response = postReorder(['id' => $items['A']->id, 'after' => $items['B']->id])->assertStatus(409);
    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('message'))->toBe(__('brume::grid.reorder.conflict'));

    postReorder(['id' => $items['B']->id, 'before' => $items['A']->id])->assertStatus(409);
    postReorder(['id' => 999, 'before' => $items['A']->id])->assertStatus(409);

    expect(SortItem::withTrashed()->pluck('sort')->all())->toBe([null, null, null]);
});

test('ids_outside_the_base_query_are_a_conflict', function () {
    $items = sortItems(['A' => 1, 'B' => 2]);
    $other = SortItem::query()->create(['shop_id' => 2, 'name' => 'E', 'sort' => 7]);
    sortRoutes();

    postReorder(['id' => $other->id, 'after' => $items['A']->id])->assertStatus(409);
    postReorder(['id' => $items['A']->id, 'after' => $other->id])->assertStatus(409);
    postReorder(['id' => $other->id])->assertStatus(409);
    postReorder(['ids' => [$items['B']->id, $items['A']->id, $other->id]])->assertStatus(409);

    expect(sortValues())->toBe(['A' => 1, 'B' => 2])
        ->and($other->fresh()->sort)->toBe(7);
});

test('reorder_works_with_string_keys', function () {
    foreach (['b' => 1, '007' => 2, 'c' => 3] as $code => $sort) {
        SortCode::query()->create(['code' => $code, 'name' => strtoupper($code), 'sort' => $sort]);
    }
    $changed = null;
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(function () use (&$changed) {
            Route::brumeGrid('codes', function () use (&$changed) {
                $grid = Grid::make(SortCode::class)->title('Codes');
                $grid->column('name', '名稱');
                $grid->orderable('sort')->orderSaved(function (array $ids) use (&$changed) {
                    $changed = $ids;
                });

                return $grid;
            });
        });
    $codes = fn () => SortCode::query()->orderBy('sort')->pluck('code')->all();

    $response = postReorder(['id' => 'b', 'after' => 'c'], '/admin/codes/_brume/reorder')->assertOk();
    expect($response->json())->toBe(['undo' => ['id' => 'b', 'before' => '007']])
        ->and($codes())->toBe(['007', 'c', 'b'])
        ->and($changed)->toBe(['007', 'c', 'b']);

    postReorder($response->json('undo'), '/admin/codes/_brume/reorder')->assertOk();
    expect($codes())->toBe(['b', '007', 'c']);

    // Exact matches only, on every driver.
    postReorder(['id' => 'B', 'after' => 'c'], '/admin/codes/_brume/reorder')->assertStatus(409);
    postReorder(['id' => 7, 'after' => 'c'], '/admin/codes/_brume/reorder')->assertStatus(409);

    postReorder(['ids' => ['c', 'b', '007']], '/admin/codes/_brume/reorder')->assertOk();
    expect($codes())->toBe(['c', 'b', '007'])
        ->and($changed)->toBe(['c', 'b', '007']);

    $items = $this->getJson('/admin/codes/_brume/reorder')->assertOk()->json('items');
    expect($items)->toBe([['id' => 'c', 'label' => 'C'], ['id' => 'b', 'label' => 'B'], ['id' => '007', 'label' => '007']]);
});

test('save_list_requires_exactly_the_scope_ids', function () {
    $items = sortItems(['A' => null, 'B' => 5, 'C' => 9]);
    [$a, $b, $c] = [$items['A']->id, $items['B']->id, $items['C']->id];
    sortRoutes();

    foreach ([[$c, $a], [$c, $a, $b, $b], [$c, $a, $b, 999], [$c, $a, $a]] as $ids) {
        $response = postReorder(['ids' => $ids])->assertStatus(409);
        expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
    }
    expect(sortValues())->toBe(['B' => 5, 'C' => 9, 'A' => null]);

    $response = postReorder(['ids' => array_merge([$c, $a, $b], range(1000, 1997))])->assertUnprocessable();
    expect($response->json('message'))->toBe('項目太多，無法一次調整順序。');

    postReorder(['ids' => 'nope'])->assertUnprocessable();
    postReorder(['ids' => [$c, 'x', $b]])->assertUnprocessable();

    $response = postReorder(['ids' => [$c, (string) $a, $b]])->assertOk();
    expect(JsonSchema::errors(json_decode($response->getContent()), 'ReorderResponse'))->toBe([])
        ->and($response->getContent())->toBe('{}')
        ->and(sortValues())->toBe(['C' => 1, 'A' => 2, 'B' => 3]);
});

test('items_lists_every_scope_row_with_label', function () {
    $items = sortItems(['A' => 3, 'B' => null, 'C' => 1]);
    SortItem::query()->create(['shop_id' => 2, 'name' => 'E', 'sort' => 2]);
    SortItem::query()->create(['shop_id' => 1, 'name' => 'gone', 'sort' => 2])->delete();
    sortRoutes();

    $response = $this->getJson('/admin/sorts/_brume/reorder?q=A&sort=-name&filter[x]=1')->assertOk();

    expect(JsonSchema::errors($response->json(), 'ReorderItemsResponse'))->toBe([])
        ->and($response->json())->toBe(['items' => [
            ['id' => $items['C']->id, 'label' => 'C'],
            ['id' => $items['A']->id, 'label' => 'A'],
            ['id' => $items['B']->id, 'label' => 'B'],
        ]]);

    // A label attribute of its own.
    sortRoutes(fn (Grid $grid) => $grid->orderable('sort', 'asc', 'shop_id'), orderable: false, uri: 'shops');
    expect($this->getJson('/admin/shops/_brume/reorder')->assertOk()->json('items.0'))->toBe(['id' => $items['C']->id, 'label' => '1']);
});

test('items_refuse_more_than_1000_rows', function () {
    SortItem::query()->insert(array_map(fn (int $i) => ['shop_id' => 1, 'name' => "N{$i}"], range(1, 1001)));
    sortRoutes();

    $response = $this->getJson('/admin/sorts/_brume/reorder')->assertUnprocessable();
    expect($response->json('message'))->toBe('項目太多，無法一次調整順序。');

    // A move has no cap.
    $first = SortItem::query()->orderBy('id')->value('id');
    postReorder(['id' => $first])->assertOk();
    expect(SortItem::query()->find($first)->sort)->toBe(1001);
});

test('move_requests_are_validated', function () {
    $items = sortItems(['A' => 1, 'B' => 2, 'C' => 3]);
    sortRoutes();

    foreach ([
        ['id' => $items['A']->id, 'before' => $items['B']->id, 'after' => $items['C']->id],
        ['id' => $items['A']->id, 'parent' => null, 'after' => $items['C']->id],
        ['id' => $items['A']->id, 'parent' => $items['B']->id],
        ['id' => $items['A']->id, 'after' => $items['A']->id],
        ['id' => 'x', 'after' => $items['C']->id],
        ['id' => $items['A']->id, 'after' => 'x'],
        ['after' => $items['C']->id],
    ] as $body) {
        $response = postReorder($body)->assertUnprocessable();
        expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
    }

    expect(sortValues())->toBe(['A' => 1, 'B' => 2, 'C' => 3]);
});

test('order_saved_hook_receives_changed_ids', function () {
    $items = sortItems(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
    $calls = [];
    $fail = false;
    sortRoutes(function (Grid $grid) use (&$calls, &$fail) {
        $grid->orderSaved(function (array $ids) use (&$calls, &$fail) {
            $calls[] = ['ids' => $ids, 'level' => DB::transactionLevel(), 'values' => sortValues()];
            if ($fail) {
                throw new RuntimeException('cache down');
            }
        });
    });

    postReorder(['id' => $items['B']->id, 'after' => $items['C']->id])->assertOk();

    expect($calls)->toBe([[
        'ids' => [$items['C']->id, $items['B']->id],
        'level' => DB::transactionLevel() + 1,
        'values' => ['A' => 1, 'C' => 2, 'B' => 3, 'D' => 4],
    ]]);

    // A failing hook rolls the new order back.
    $fail = true;
    postReorder(['ids' => [$items['D']->id, $items['C']->id, $items['B']->id, $items['A']->id]])->assertServerError();
    expect(sortValues())->toBe(['A' => 1, 'C' => 2, 'B' => 3, 'D' => 4]);
});

test('orderable_and_editable_on_the_same_column_throw', function () {
    $grid = Grid::make(SortItem::class);
    $grid->column('sort')->editable(Editor::number());
    expect(fn () => $grid->orderable('sort'))->toThrow(LogicException::class, '排序欄位「sort」');

    // Declared the other way round, the page refuses to render.
    $this->withoutExceptionHandling();
    sortItems(['A' => 1]);
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('late', function () {
            $grid = Grid::make(SortItem::class)->orderable('sort');
            $grid->column('sort')->editable(Editor::number());

            return $grid;
        }));

    expect(fn () => $this->get('/admin/late'))->toThrow(LogicException::class, '排序欄位「sort」')
        ->and(fn () => $this->postJson('/admin/late/_brume/reorder', ['ids' => []]))->toThrow(LogicException::class, '排序欄位「sort」');
});

test('endpoint_is_404_when_grid_is_not_orderable', function () {
    sortItems(['A' => 1]);
    sortRoutes(fn (Grid $grid) => $grid->exportable(), orderable: false);

    $page = $this->get('/admin/sorts')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page)->not->toHaveKey('ordering')
        ->and($page['endpoints'])->not->toHaveKey('reorder');

    foreach ([$this->getJson('/admin/sorts/_brume/reorder'), postReorder(['ids' => []])] as $response) {
        $response->assertNotFound();
        expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
    }
});
