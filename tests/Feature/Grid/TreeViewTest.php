<?php

use Brume\Form\Form;
use Brume\Grid\Actions\Action;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Resource\Resource;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\TreeCode;
use Brume\Tests\Support\Models\TreeNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

class TreeNodeResource extends Resource
{
    protected string $model = TreeNode::class;

    protected string $name = '節點';

    public static bool $addChild = true;

    protected function query(): Builder
    {
        return TreeNode::query()->where('shop_id', 1);
    }

    protected function grid(Grid $grid): void
    {
        $grid->column('name', '名稱')->primary();
        $grid->treeView(maxDepth: 2, addChild: static::$addChild);
    }

    protected function form(Form $form): void
    {
        $form->text('name')->required();
        $form->number('parent_id');
    }
}

/**
 * /admin/tree: shop 1's nodes on a Route::brumeGrid page behind brume.auth, as a tree on
 * parent_id / order (treeView(...$tree), unless $tree is null); $declare configures the grid further.
 */
function treeRoutes(?Closure $declare = null, ?array $tree = [], string $uri = 'tree'): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid($uri, function () use ($declare, $tree) {
            $grid = Grid::make(TreeNode::query()->where('shop_id', 1))->title('Tree');
            $grid->column('name', '名稱')->primary();
            if ($tree !== null) {
                $grid->treeView(...$tree);
            }
            $declare?->__invoke($grid);

            return $grid;
        }));
}

/**
 * Shop 1's nodes, created in the order given: name => [parent name (or a raw parent_id value
 * when it is not a name given before), order].
 *
 * @param  array<string, array{0: string|int|null, 1: int|null}>  $spec
 * @return array<string, TreeNode>
 */
function treeNodes(array $spec, int|string|null $root = null): array
{
    $nodes = [];
    foreach ($spec as $name => [$parent, $order]) {
        $parentId = match (true) {
            $parent === null => $root,
            is_string($parent) && isset($nodes[$parent]) => $nodes[$parent]->id,
            default => $parent,
        };
        $nodes[$name] = TreeNode::query()->create(['shop_id' => 1, 'name' => $name, 'parent_id' => $parentId, 'order' => $order]);
    }

    return $nodes;
}

/**
 * Shop 1's nodes by id: name => [parent name (or the raw parent_id when no shop 1 node has it), order].
 *
 * @return array<string, array{0: string|int|null, 1: int|null}>
 */
function treeValues(): array
{
    $nodes = TreeNode::query()->where('shop_id', 1)->orderBy('id')->get();
    $names = $nodes->pluck('name', 'id')->all();

    return $nodes->mapWithKeys(fn (TreeNode $node) => [
        $node->name => [$names[$node->parent_id] ?? $node->parent_id, $node->order],
    ])->all();
}

function treePage(string $uri = '/admin/tree'): array
{
    return test()->get($uri)->assertOk()->viewData('page')['props']['envelope']['page'];
}

function postTreeMove(array $body, string $uri = '/admin/tree/_brume/reorder'): TestResponse
{
    return test()->postJson($uri, $body);
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
});

test('tree_schema_lists_all_rows_depth_first_with_parent_meta', function () {
    $n = treeNodes([
        'A' => [null, 2],
        'B' => [null, 1],
        'B1' => ['B', 2],
        'B2' => ['B', 1],
        'A1' => ['A', null],
        'B2a' => ['B2', 1],
    ]);
    TreeNode::query()->create(['shop_id' => 2, 'name' => 'other', 'order' => 0]);
    treeRoutes(tree: ['maxDepth' => 3]);

    $envelope = $this->get('/admin/tree?q=B&sort=-name&filter[x]=1')->assertOk()->viewData('page')['props']['envelope'];
    $page = $envelope['page'];

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([])
        ->and($page['ordering'])->toBe(['mode' => 'tree', 'column' => 'order', 'direction' => 'asc', 'maxDepth' => 3])
        ->and($page['endpoints']['reorder'])->toBe('/admin/tree/_brume/reorder')
        ->and(array_column($page['rows'], 'name'))->toBe(['B', 'B2', 'B2a', 'B1', 'A', 'A1'])
        ->and(array_map(fn (array $row) => $row['_meta']['parent'], $page['rows']))
        ->toBe([null, $n['B']->id, $n['B2']->id, $n['B']->id, null, $n['A']->id])
        ->and($page['pagination'])->toBe(['page' => 1, 'perPage' => 6, 'total' => 6, 'lastPage' => 1])
        ->and($page['actions']['batch'])->toBe([]);

    // Without maxDepth the key is left out; an empty tree is still one page.
    TreeNode::query()->delete();
    treeRoutes(uri: 'plain');
    $plain = treePage('/admin/plain');
    expect($plain['ordering'])->toBe(['mode' => 'tree', 'column' => 'order', 'direction' => 'asc'])
        ->and($plain['rows'])->toBe([])
        ->and($plain['pagination'])->toBe(['page' => 1, 'perPage' => 1, 'total' => 0, 'lastPage' => 1]);
});

test('tree_move_changes_parent_and_renumbers_both_sibling_groups', function () {
    $n = treeNodes([
        'A' => [null, 1], 'B' => [null, 2],
        'A1' => ['A', 1], 'A2' => ['A', 2], 'A3' => ['A', 3],
        'B1' => ['B', 1], 'B2' => ['B', 2],
    ]);
    $stamps = TreeNode::query()->orderBy('id')->pluck('updated_at', 'name')->map(fn ($at) => (string) $at)->all();
    $changed = null;
    treeRoutes(function (Grid $grid) use (&$changed) {
        $grid->orderSaved(function (array $ids) use (&$changed) {
            $changed = $ids;
        });
    });
    $this->travel(2)->days();
    $updates = 0;
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
            $updates++;
        }
    });

    $response = postTreeMove(['id' => $n['A2']->id, 'parent' => $n['B']->id, 'after' => $n['B1']->id])->assertOk();

    expect(JsonSchema::errors($response->json(), 'ReorderResponse'))->toBe([])
        ->and($response->json())->toBe(['undo' => ['id' => $n['A2']->id, 'parent' => $n['A']->id, 'before' => $n['A3']->id]])
        ->and(treeValues())->toBe([
            'A' => [null, 1], 'B' => [null, 2],
            'A1' => ['A', 1], 'A2' => ['B', 2], 'A3' => ['A', 2],
            'B1' => ['B', 1], 'B2' => ['B', 3],
        ])
        ->and($updates)->toBe(3)
        ->and($changed)->toBe([$n['A3']->id, $n['A2']->id, $n['B2']->id])
        ->and(TreeNode::query()->orderBy('id')->pluck('updated_at', 'name')->map(fn ($at) => (string) $at)->all())->toBe($stamps);

    // Within the same parent, only that group is renumbered; neither anchor means last.
    postTreeMove(['id' => $n['A1']->id, 'parent' => $n['A']->id])->assertOk()
        ->assertExactJson(['undo' => ['id' => $n['A1']->id, 'parent' => $n['A']->id, 'before' => $n['A3']->id]]);
    expect(treeValues())->toMatchArray(['A1' => ['A', 2], 'A3' => ['A', 1]])
        ->and($changed)->toBe([$n['A3']->id, $n['A1']->id]);
});

test('tree_with_zero_root_and_null_orders_renumbers_siblings', function () {
    $n = treeNodes([
        'R1' => [null, null],
        'R2' => [null, 5],
        'R3' => [null, 5],
        'C' => ['R2', null],
    ], root: 0);
    treeRoutes(tree: ['root' => 0]);

    $page = treePage();
    expect(array_column($page['rows'], 'name'))->toBe(['R2', 'C', 'R3', 'R1'])
        ->and(array_map(fn (array $row) => $row['_meta']['parent'], $page['rows']))->toBe([null, $n['R2']->id, null, null]);

    $response = postTreeMove(['id' => $n['R1']->id, 'parent' => null, 'before' => $n['R2']->id])->assertOk();
    expect($response->json())->toBe(['undo' => ['id' => $n['R1']->id, 'parent' => null, 'after' => $n['R3']->id]])
        ->and(treeValues())->toBe(['R1' => [0, 1], 'R2' => [0, 2], 'R3' => [0, 3], 'C' => ['R2', null]]);

    // Out to the roots: the parent is written as the root value, 0.
    postTreeMove(['id' => $n['C']->id, 'parent' => null])->assertOk()
        ->assertExactJson(['undo' => ['id' => $n['C']->id, 'parent' => $n['R2']->id]]);
    expect(treeValues())->toBe(['R1' => [0, 1], 'R2' => [0, 2], 'R3' => [0, 3], 'C' => [0, 4]])
        ->and(TreeNode::query()->whereNull('parent_id')->count())->toBe(0);
});

test('tree_move_into_descendant_is_rejected', function () {
    $n = treeNodes(['A' => [null, 1], 'A1' => ['A', 1], 'A1a' => ['A1', 1], 'B' => [null, 2]]);
    treeRoutes();
    $before = treeValues();

    foreach ([$n['A1a']->id, $n['A1']->id, $n['A']->id] as $parent) {
        $response = postTreeMove(['id' => $n['A']->id, 'parent' => $parent])->assertUnprocessable();
        expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
    }

    expect(treeValues())->toBe($before);
});

test('tree_move_beyond_max_depth_is_rejected', function () {
    $n = treeNodes([
        'A' => [null, 1], 'A1' => ['A', 1], 'A1a' => ['A1', 1],
        'B' => [null, 2], 'B1' => ['B', 1],
    ]);
    treeRoutes(tree: ['maxDepth' => 3]);

    // B has a child: under A1 (depth 2) it would reach depth 4.
    $response = postTreeMove(['id' => $n['B']->id, 'parent' => $n['A1']->id])->assertUnprocessable();
    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('message'))->toBe('最多只能有 3 層。')
        ->and(treeValues()['B'])->toBe([null, 2]);

    // A leaf fits there, and B with its child fits under A.
    postTreeMove(['id' => $n['B1']->id, 'parent' => $n['A1']->id])->assertOk();
    postTreeMove(['id' => $n['B']->id, 'parent' => $n['A']->id])->assertOk();
    expect(treeValues())->toMatchArray(['B' => ['A', 2], 'B1' => ['A1', 2]]);
});

test('tree_anchor_must_be_a_child_of_the_new_parent', function () {
    $n = treeNodes(['A' => [null, 1], 'A1' => ['A', 1], 'B' => [null, 2], 'B1' => ['B', 1]]);
    $other = TreeNode::query()->create(['shop_id' => 2, 'name' => 'other', 'order' => 1]);
    treeRoutes();
    $before = treeValues();

    foreach ([
        [409, ['id' => $n['A1']->id, 'parent' => $n['B']->id, 'after' => $n['A']->id]],
        [409, ['id' => $n['A1']->id, 'parent' => null, 'before' => $n['B1']->id]],
        [409, ['id' => $n['A1']->id, 'parent' => $n['B']->id, 'before' => 999]],
        [409, ['id' => $n['A1']->id, 'parent' => $other->id]],
        [409, ['id' => $other->id, 'parent' => null]],
        [422, ['id' => $n['A1']->id, 'parent' => $n['B']->id, 'after' => $n['A1']->id]],
        [422, ['id' => $n['A1']->id, 'after' => $n['B1']->id]],
        [422, ['id' => $n['A1']->id, 'parent' => 'x']],
        [422, ['id' => $n['A1']->id, 'parent' => $n['B']->id, 'before' => $n['B1']->id, 'after' => $n['B1']->id]],
        [422, ['ids' => [$n['A']->id, $n['A1']->id, $n['B']->id, $n['B1']->id]]],
    ] as [$status, $body]) {
        $response = postTreeMove($body)->assertStatus($status);
        expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
    }

    // No 調整順序 dialog in tree mode.
    $this->getJson('/admin/tree/_brume/reorder')->assertNotFound();

    expect(treeValues())->toBe($before);

    postTreeMove(['id' => $n['A1']->id, 'parent' => $n['B']->id, 'before' => $n['B1']->id])->assertOk();
    expect(treeValues())->toMatchArray(['A1' => ['B', 1], 'B1' => ['B', 2]]);
});

test('tree_undo_restores_parent_and_position', function () {
    $n = treeNodes([
        'A' => [null, 1], 'A1' => ['A', 1], 'A2' => ['A', 2], 'A3' => ['A', 3],
        'B' => [null, 2], 'B1' => ['B', 1],
    ]);
    treeRoutes();
    $original = treeValues();

    $undo = postTreeMove(['id' => $n['A2']->id, 'parent' => $n['B']->id])->assertOk()->json('undo');
    expect($undo)->toBe(['id' => $n['A2']->id, 'parent' => $n['A']->id, 'before' => $n['A3']->id])
        ->and(treeValues())->toMatchArray(['A2' => ['B', 2], 'A3' => ['A', 2]]);
    postTreeMove($undo)->assertOk();
    expect(treeValues())->toBe($original);

    // The last child comes back after its old neighbor.
    $undo = postTreeMove(['id' => $n['A3']->id, 'parent' => null, 'before' => $n['A']->id])->assertOk()->json('undo');
    expect($undo)->toBe(['id' => $n['A3']->id, 'parent' => $n['A']->id, 'after' => $n['A2']->id]);
    postTreeMove($undo)->assertOk();
    expect(treeValues())->toBe($original);

    // An only child comes back as the last (only) child.
    $undo = postTreeMove(['id' => $n['B1']->id, 'parent' => $n['A']->id, 'after' => $n['A1']->id])->assertOk()->json('undo');
    expect($undo)->toBe(['id' => $n['B1']->id, 'parent' => $n['B']->id]);
    postTreeMove($undo)->assertOk();
    expect(treeValues())->toBe($original);
});

test('orphans_are_shown_as_roots', function () {
    $other = TreeNode::query()->create(['shop_id' => 2, 'name' => 'other', 'order' => 1]);
    $n = treeNodes([
        'X' => [999, 1],
        'Y' => [$other->id, 2],
        'P' => [null, 3],
        'Q' => ['P', 4],
        'Qc' => ['Q', 1],
        'S' => [null, 5],
    ]);
    // P and Q point at each other, S at itself.
    $n['P']->update(['parent_id' => $n['Q']->id]);
    $n['S']->update(['parent_id' => $n['S']->id]);
    treeRoutes();

    $rows = treePage()['rows'];
    expect(array_column($rows, 'name'))->toBe(['X', 'Y', 'P', 'Q', 'Qc', 'S'])
        ->and(array_map(fn (array $row) => $row['_meta']['parent'], $rows))->toBe([null, null, null, null, $n['Q']->id, null]);

    // An orphan moves like any root, and keeps its stored parent while it stays among the roots.
    postTreeMove(['id' => $n['Y']->id, 'parent' => null, 'before' => $n['X']->id])->assertOk();
    expect(array_column(treePage()['rows'], 'name'))->toBe(['Y', 'X', 'P', 'Q', 'Qc', 'S'])
        ->and(treeValues()['Y'])->toBe([$other->id, 1]);
});

test('orphan_reordered_among_roots_keeps_its_stored_parent', function () {
    $other = TreeNode::query()->create(['shop_id' => 2, 'name' => 'other', 'order' => 1]);
    $n = treeNodes(['A' => [null, 1], 'X' => [999, 2], 'Y' => [$other->id, 3]]);
    treeRoutes();

    postTreeMove(['id' => $n['X']->id, 'parent' => null])->assertOk();
    postTreeMove(['id' => $n['Y']->id, 'parent' => null, 'before' => $n['A']->id])->assertOk();

    expect(array_column(treePage()['rows'], 'name'))->toBe(['Y', 'A', 'X'])
        ->and(treeValues())->toBe(['A' => [null, 2], 'X' => [999, 3], 'Y' => [$other->id, 1]]);
});

test('orphan_moved_under_a_visible_parent_gets_that_parent', function () {
    $n = treeNodes(['A' => [null, 1], 'A1' => ['A', 1], 'X' => [999, 2]]);
    treeRoutes();

    postTreeMove(['id' => $n['X']->id, 'parent' => $n['A']->id, 'before' => $n['A1']->id])->assertOk();

    expect(treeValues())->toBe(['A' => [null, 1], 'A1' => ['A', 2], 'X' => ['A', 1]]);
});

test('row_deeper_than_max_depth_can_be_reordered_in_place_but_not_moved_deeper', function () {
    // Legacy data: A1a and A1b sit at depth 3 under maxDepth 2.
    $n = treeNodes(['A' => [null, 1], 'A1' => ['A', 1], 'A1a' => ['A1', 1], 'A1b' => ['A1', 2], 'B' => [null, 2]]);
    treeRoutes(tree: ['maxDepth' => 2]);

    postTreeMove(['id' => $n['A1a']->id, 'parent' => $n['A1']->id, 'after' => $n['A1b']->id])->assertOk();
    expect(treeValues())->toMatchArray(['A1a' => ['A1', 2], 'A1b' => ['A1', 1]]);

    // A1 with its children (height 2) reorders in place under A too.
    postTreeMove(['id' => $n['A1']->id, 'parent' => $n['A']->id])->assertOk();

    $response = postTreeMove(['id' => $n['A1a']->id, 'parent' => $n['A1b']->id])->assertUnprocessable();
    expect($response->json('message'))->toBe('最多只能有 2 層。')
        ->and(treeValues()['A1a'])->toBe(['A1', 2]);

    // Moving to another parent still checks the depth.
    postTreeMove(['id' => $n['A1']->id, 'parent' => $n['B']->id])->assertUnprocessable();
});

test('tree_view_with_filters_or_quick_search_throws', function () {
    $tree = fn () => Grid::make(TreeNode::class)->treeView();
    $cases = [
        'quickSearch()' => fn () => $tree()->quickSearch('name'),
        '篩選' => function () use ($tree) {
            $grid = $tree();
            $grid->filters()->equal('name', '名稱');

            return $grid;
        },
        '篩選（欄位「name」）' => function () use ($tree) {
            $grid = $tree();
            $grid->column('name')->filter('input');

            return $grid;
        },
        'sortable()' => function () use ($tree) {
            $grid = $tree();
            $grid->column('name')->sortable();

            return $grid;
        },
        'batchActions()' => fn () => $tree()->batchActions(Action::make('x', 'X')->handle(fn () => null)),
        'editable()' => function () use ($tree) {
            $grid = $tree();
            $grid->column('name')->editable(Editor::text());

            return $grid;
        },
    ];

    foreach ($cases as $message => $make) {
        expect(fn () => $make()->ordering())->toThrow(LogicException::class, $message);
    }

    expect(fn () => Grid::make(TreeNode::class)->treeView()->orderable('order'))->toThrow(LogicException::class, 'treeView()')
        ->and(fn () => Grid::make(TreeNode::class)->orderable('order')->treeView())->toThrow(LogicException::class, 'orderable()')
        ->and(fn () => Grid::make(TreeNode::class)->treeView(maxDepth: 0))->toThrow(InvalidArgumentException::class, 'maxDepth');

    // The page refuses to render.
    $this->withoutExceptionHandling();
    treeRoutes(fn (Grid $grid) => $grid->quickSearch('name'));
    expect(fn () => $this->get('/admin/tree'))->toThrow(LogicException::class, 'quickSearch()');
});

test('add_child_action_prefills_the_parent_field', function () {
    $n = treeNodes(['A' => [null, 1], 'A1' => ['A', 1]]);
    $other = TreeNode::query()->create(['shop_id' => 2, 'name' => 'other', 'order' => 1]);
    Route::prefix('admin')->name('brume.')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])
        ->group(fn () => Route::brumeResource('nodes', TreeNodeResource::class));
    Route::getRoutes()->refreshNameLookups();

    $envelope = $this->get('/admin/nodes')->assertOk()->viewData('page')['props']['envelope'];
    $page = $envelope['page'];
    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([])
        ->and(array_column($page['actions']['row'], 'label', 'key'))->toBe(['add-child' => '新增子項', 'delete' => '刪除'])
        ->and($page['actions']['batch'])->toBe([])
        // A1 is at maxDepth 2: no child can go under it.
        ->and(array_column(array_column($page['rows'], '_meta'), 'actions'))->toBe([['add-child', 'delete'], ['delete']]);

    $response = $this->postJson('/admin/nodes/_brume/actions/add-child', ['target' => 'row', 'ids' => [$n['A']->id]])->assertOk();
    expect(JsonSchema::errors($response->json(), 'ActionResponse'))->toBe([])
        ->and($response->json('feedback'))->toBe(['redirect' => '/admin/nodes/create?_brume_parent='.$n['A']->id]);
    $this->postJson('/admin/nodes/_brume/actions/add-child', ['target' => 'row', 'ids' => [$n['A1']->id]])->assertForbidden();
    $this->postJson('/admin/nodes/_brume/actions/delete', ['target' => 'batch', 'ids' => [$n['A1']->id]])->assertNotFound();

    $values = fn (string $query) => $this->get('/admin/nodes/create'.$query, ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    expect($values('?_brume_parent='.$n['A']->id)['parent_id'])->toBe($n['A']->id)
        ->and($values('?_brume_parent='.$other->id)['parent_id'])->toBeNull()
        ->and($values('?_brume_parent=nope')['parent_id'])->toBeNull()
        ->and($values('')['parent_id'])->toBeNull();

    // addChild: false leaves the action out.
    TreeNodeResource::$addChild = false;
    try {
        expect(array_column(treePage('/admin/nodes')['actions']['row'], 'key'))->toBe(['delete']);
    } finally {
        TreeNodeResource::$addChild = true;
    }

    // Without a resource there is no create form to open.
    treeRoutes();
    expect(treePage()['actions']['row'])->toBe([]);
});

test('tree_view_ignores_per_page', function () {
    TreeNode::query()->insert(array_map(fn (int $i) => ['shop_id' => 1, 'name' => "N{$i}", 'order' => $i], range(1, 30)));
    treeRoutes(fn (Grid $grid) => $grid->perPage(10));

    $page = treePage('/admin/tree?per_page=10&page=2');

    expect($page['rows'])->toHaveCount(30)
        ->and($page['rows'][0]['name'])->toBe('N1')
        ->and($page['pagination'])->toBe(['page' => 1, 'perPage' => 30, 'total' => 30, 'lastPage' => 1]);
});

test('tree_works_with_string_keys', function () {
    foreach ([['r', null, 1], ['007', 'r', 1], ['b', 'r', 2], ['s', null, 2]] as [$code, $parent, $sort]) {
        TreeCode::query()->create(['code' => $code, 'name' => strtoupper($code), 'parent_code' => $parent, 'sort' => $sort]);
    }
    $changed = null;
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(function () use (&$changed) {
            Route::brumeGrid('codes', function () use (&$changed) {
                $grid = Grid::make(TreeCode::class)->title('Codes');
                $grid->column('name', '名稱');
                $grid->treeView('parent_code', 'sort')->orderSaved(function (array $ids) use (&$changed) {
                    $changed = $ids;
                });

                return $grid;
            });
        });

    $rows = treePage('/admin/codes')['rows'];
    expect(array_column($rows, 'id'))->toBe(['r', '007', 'b', 's'])
        ->and(array_map(fn (array $row) => $row['_meta']['parent'], $rows))->toBe([null, 'r', 'r', null]);

    $response = postTreeMove(['id' => '007', 'parent' => 's'], '/admin/codes/_brume/reorder')->assertOk();
    expect($response->json())->toBe(['undo' => ['id' => '007', 'parent' => 'r', 'before' => 'b']])
        ->and($changed)->toBe(['b', '007'])
        ->and(TreeCode::query()->orderBy('code')->get(['code', 'parent_code', 'sort'])->toArray())->toBe([
            ['code' => '007', 'parent_code' => 's', 'sort' => 1],
            ['code' => 'b', 'parent_code' => 'r', 'sort' => 1],
            ['code' => 'r', 'parent_code' => null, 'sort' => 1],
            ['code' => 's', 'parent_code' => null, 'sort' => 2],
        ]);

    postTreeMove(['id' => '7', 'parent' => 's'], '/admin/codes/_brume/reorder')->assertStatus(409);
    postTreeMove(['id' => 'b', 'parent' => 'R'], '/admin/codes/_brume/reorder')->assertStatus(409);
});
