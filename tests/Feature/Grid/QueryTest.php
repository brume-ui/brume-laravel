<?php

use Brume\Grid\Grid;
use Brume\Grid\GridQuery;
use Brume\Grid\Query\Like;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

/**
 * Users named by $names (username "u{n}", in order), and their ids in the same order. The ids
 * are read back rather than assumed to start at 1: MySQL does not reset AUTO_INCREMENT when
 * RefreshDatabase rolls a test back.
 *
 * @param  list<string>  $names
 * @return list<int>
 */
function queryUsers(array $names): array
{
    // A bulk insert skips the hashed password cast, which is slow for many rows.
    User::query()->insert(array_map(fn (int $i, string $name) => [
        'username' => 'u'.($i + 1), 'password' => 'x', 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
    ], array_keys($names), array_values($names)));

    $ids = User::query()->whereIn('username', array_map(fn (int $i) => 'u'.($i + 1), array_keys($names)))->pluck('id', 'username');

    return array_map(fn (int $i) => $ids['u'.($i + 1)], array_keys($names));
}

function queryGrid(): Grid
{
    $grid = Grid::make(User::class)->title('Users')->perPage(10);
    $grid->column('username')->sortable();
    $grid->column('name')->sortable();
    $grid->column('created_at');

    return $grid;
}

function queryPage(Grid $grid, array $query = []): array
{
    return $grid->toSchema(Request::create('/admin/users', 'GET', $query));
}

/**
 * SQL with MySQL's backtick identifiers written as SQLite's double quotes, to assert on either.
 */
function portableSql(string $sql): string
{
    return str_replace('`', '"', $sql);
}

/**
 * The SELECT that fetched the page rows.
 */
function pageSelect(): string
{
    return collect(DB::getQueryLog())->pluck('query')->last(fn ($sql) => str_contains($sql, 'limit'));
}

/**
 * The SELECT a page of 10 users ordered by $orders ([column, direction] pairs) runs, written by
 * the connection's own grammar so the comparison holds on any driver.
 *
 * @param  list<array{0: string, 1: string}>  $orders
 */
function usersPageSql(array $orders): string
{
    $query = User::query();

    foreach ($orders as [$column, $direction]) {
        $query->orderBy($column, $direction);
    }

    return $query->limit(10)->offset(0)->toSql();
}

it('sorts by a sortable column, descending with a leading minus', function () {
    queryUsers(['Bea', 'Ada', 'Cy']);

    $page = queryPage(queryGrid(), ['sort' => '-name']);

    expect(array_column($page['rows'], 'name'))->toBe(['Cy', 'Bea', 'Ada'])
        ->and($page['query']['sort'])->toBe(['key' => 'name', 'direction' => 'desc'])
        ->and(queryPage(queryGrid(), ['sort' => 'name'])['rows'][0]['name'])->toBe('Ada');
});

it('ignores a sort on an undeclared, unknown or non-sortable key', function (mixed $sort) {
    $this->actingAs($user = brumeUser(), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->get('/grid-sort', fn () => queryGrid());

    $page = $this->get('/grid-sort?'.http_build_query(['sort' => $sort]))->assertOk()
        ->viewData('page')['props']['envelope']['page'];

    expect($page['query'])->not->toHaveKey('sort')
        ->and(array_column($page['rows'], 'id'))->toBe([$user->id]);
})->with([
    'password' => ['password'],
    'unknown' => ['unknown'],
    'descending unknown' => ['-unknown'],
    'declared but not sortable' => ['created_at'],
    'bare minus' => ['-'],
    'array' => [['name']],
]);

it('sort by a non-unique column adds a primary key tiebreaker', function () {
    $ids = queryUsers(['Same', 'Same', 'Same']);
    DB::enableQueryLog();

    $page = queryPage(queryGrid(), ['sort' => '-name']);

    expect(pageSelect())->toBe(usersPageSql([['name', 'desc'], ['brume_users.id', 'asc']]))
        ->and(array_column($page['rows'], 'id'))->toBe($ids);
});

it('does not repeat the primary key when sorting by it', function () {
    [$a, $b] = queryUsers(['A', 'B']);
    $grid = queryGrid();
    $grid->column('id')->sortable();
    DB::enableQueryLog();

    $page = queryPage($grid, ['sort' => '-id']);

    expect(pageSelect())->toBe(usersPageSql([['id', 'desc']]))
        ->and(array_column($page['rows'], 'id'))->toBe([$b, $a]);
});

it('replaces the query\'s own order when a sort applies', function () {
    queryUsers(['B', 'A', 'C']);
    $grid = Grid::make(User::query()->orderByDesc('username'));
    $grid->column('name')->sortable();
    DB::enableQueryLog();

    expect(array_column(queryPage($grid, ['sort' => 'name'])['rows'], 'name'))->toBe(['A', 'B', 'C'])
        ->and(portableSql(pageSelect()))->not->toContain('"username" desc')
        ->and(array_column(queryPage($grid)['rows'], 'name'))->toBe(['C', 'A', 'B']);
});

it('sorts with a closure, which also serves dotted keys', function () {
    [$b, $a] = queryUsers(['B', 'A']);
    $grid = Grid::make(User::class);
    $grid->column('profile.name')->sortable(fn ($query, string $direction) => $query->orderBy('name', $direction));

    $page = queryPage($grid, ['sort' => 'profile.name']);

    expect(array_column($page['rows'], 'id'))->toBe([$a, $b])
        ->and($page['query']['sort'])->toBe(['key' => 'profile.name', 'direction' => 'asc']);
});

it('requires a closure to sort a dotted column', function () {
    Grid::make(User::class)->column('profile.name')->sortable();
})->throws(InvalidArgumentException::class, 'profile.name');

it('applies defaultSort on the server without echoing it in query.sort', function () {
    queryUsers(['B', 'A', 'C']);
    $grid = queryGrid()->defaultSort('name', 'desc');
    DB::enableQueryLog();

    $page = queryPage($grid);

    expect(array_column($page['rows'], 'name'))->toBe(['C', 'B', 'A'])
        ->and(pageSelect())->toBe(usersPageSql([['name', 'desc'], ['brume_users.id', 'asc']]))
        // R11: only a sort from the URL is echoed, so the header's sort cycle can reach "unsorted".
        ->and($page['query'])->not->toHaveKey('sort')
        ->and($page['columns'][1])->toMatchArray(['key' => 'name', 'sortable' => true]);

    $sorted = queryPage($grid, ['sort' => 'username']);
    expect(array_column($sorted['rows'], 'name'))->toBe(['B', 'A', 'C'])
        ->and($sorted['query']['sort'])->toBe(['key' => 'username', 'direction' => 'asc']);
});

it('rejects a default sort direction other than asc or desc', function () {
    queryGrid()->defaultSort('name', 'down');
})->throws(InvalidArgumentException::class, 'down');

it('honours per_page only when it is one of the options', function (string $perPage, int $expected) {
    queryUsers(array_fill(0, 60, 'x'));

    $page = queryPage(queryGrid(), ['per_page' => $perPage]);

    expect($page['pagination']['perPage'])->toBe($expected)
        ->and($page['rows'])->toHaveCount($expected)
        ->and($page['query']['perPage'])->toBe($expected);
})->with([
    'an option' => ['50', 50],
    'not an option' => ['7', 10],
    'too large' => ['100000', 10],
    'not a number' => ['abc', 10],
    'zero' => ['0', 10],
]);

it('merges perPage into the default per-page options, sorted', function () {
    expect(queryPage(Grid::make(User::class))['perPageOptions'])->toBe([10, 20, 50, 100])
        ->and(queryPage(Grid::make(User::class)->perPage(15))['perPageOptions'])->toBe([10, 15, 20, 50, 100])
        ->and(queryPage(Grid::make(User::class)->perPage(25)->perPageOptions([100, 5, 25, 5]))['perPageOptions'])->toBe([5, 25, 100])
        ->and(queryPage(Grid::make(User::class)->perPageOptions([30])->perPage(30))['query']['perPage'])->toBe(30);
});

it('rejects per-page options that are not positive integers', function (array $options) {
    Grid::make(User::class)->perPageOptions($options);
})->with([
    'zero' => [[0, 10]],
    'string' => [['10']],
    'empty' => [[]],
])->throws(InvalidArgumentException::class);

it('quick-searches the declared columns with OR', function () {
    [$ada, , $alan] = queryUsers(['Ada Lovelace', 'Grace Hopper', 'Alan Turing']);
    User::query()->whereKey($alan)->update(['username' => 'ada_fan']);
    $grid = queryGrid()->quickSearch('username', 'name');
    // HandleBrumeRequests sets the locale per request; this calls toSchema() directly.
    app()->setLocale('zh_TW');

    $page = queryPage($grid, ['q' => 'ada']);

    expect(array_column($page['rows'], 'id'))->toBe([$ada, $alan])
        ->and($page['query']['q'])->toBe('ada')
        ->and($page['appliedFilters'])->toBe([['key' => 'q', 'label' => '搜尋', 'display' => 'ada']]);
});

it('quick search treats LIKE wildcards literally', function (string $q, array $names) {
    queryUsers(['50%off', '500ff', 'a_b', 'axb', 'x!y']);
    $grid = queryGrid()->quickSearch('name');

    expect(array_column(queryPage($grid, ['q' => $q])['rows'], 'name'))->toBe($names);
})->with([
    'percent' => ['50%', ['50%off']],
    'underscore' => ['a_b', ['a_b']],
    'escape character' => ['x!y', ['x!y']],
    'plain' => ['50', ['50%off', '500ff']],
]);

it('quick search ignores case', function () {
    queryUsers(['ada', 'BOB', 'carol']);
    $grid = queryGrid()->quickSearch('name');

    expect(array_column(queryPage($grid, ['q' => 'ADA'])['rows'], 'name'))->toBe(['ada'])
        ->and(array_column(queryPage($grid, ['q' => 'bob'])['rows'], 'name'))->toBe(['BOB']);
});

it('quick-searches a relation column and a closure', function () {
    [, $bob, $cy] = queryUsers(['Ada', 'Bob', 'Cy']);
    User::resolveRelationUsing('orders', fn (User $user) => $user->hasMany(Order::class, 'customer', 'username'));
    Order::factory()->create(['number' => 'BR-777', 'customer' => 'u2']);

    $grid = queryGrid()->quickSearch('orders.number', fn ($query, string $term) => $query->where('name', $term));

    expect(array_column(queryPage($grid, ['q' => 'br-77'])['rows'], 'id'))->toBe([$bob])
        ->and(array_column(queryPage($grid, ['q' => 'Cy'])['rows'], 'id'))->toBe([$cy]);
});

it('requires at least one quick search column', function () {
    Grid::make(User::class)->quickSearch();
})->throws(InvalidArgumentException::class);

it('outputs quickSearch only when declared, with its placeholder', function () {
    expect(queryPage(queryGrid()))->not->toHaveKey('quickSearch')
        ->and(json_encode(queryPage(queryGrid()->quickSearch('name'))['quickSearch']))->toBe('{}')
        ->and(queryPage(queryGrid()->quickSearch('name')->quickSearchPlaceholder('搜尋帳號或名稱'))['quickSearch'])
        ->toBe(['placeholder' => '搜尋帳號或名稱']);
});

it('ignores q when the grid has no quick search, and a blank q', function (array $query) {
    queryUsers(['Ada', 'Bob']);

    $page = queryPage(isset($query['searchable']) ? queryGrid()->quickSearch('name') : queryGrid(), ['q' => $query['q']]);

    expect($page['rows'])->toHaveCount(2)
        ->and($page['query'])->not->toHaveKey('q')
        ->and($page['appliedFilters'])->toBe([]);
})->with([
    'no quick search' => [['q' => 'Ada']],
    'blank' => [['q' => '   ', 'searchable' => true]],
    'array' => [['q' => ['Ada'], 'searchable' => true]],
]);

it('clamps the page when a search shrinks the result', function () {
    queryUsers(array_map(fn ($i) => "Name {$i}", range(1, 25)));
    $grid = queryGrid()->quickSearch('username');

    // u1 and u10–u19: 11 rows, so 2 pages of 10.
    $page = queryPage($grid, ['q' => 'u1', 'page' => '3']);

    expect($page['pagination'])->toBe(['page' => 2, 'perPage' => 10, 'total' => 11, 'lastPage' => 2])
        ->and($page['rows'])->toHaveCount(1);
});

it('echoes the corrected query in page.query', function () {
    queryUsers(['Ada']);
    $grid = queryGrid()->quickSearch('name');

    $query = queryPage($grid, ['sort' => '-username', 'per_page' => '7', 'q' => '  Ada  ', 'page' => 'x'])['query'];

    expect($query['sort'])->toBe(['key' => 'username', 'direction' => 'desc'])
        ->and($query['q'])->toBe('Ada')
        ->and($query['perPage'])->toBe(10)
        ->and(json_encode($query['filters']))->toBe('{}')
        ->and(array_keys($query))->toBe(['sort', 'q', 'perPage', 'filters']);

    expect(array_keys(queryPage($grid)['query']))->toBe(['perPage', 'filters']);
});

it('parses the request into a GridQuery', function () {
    $grid = queryGrid()->quickSearch('name');

    $query = GridQuery::fromRequest(Request::create('/', 'GET', ['sort' => '-name', 'page' => '4', 'per_page' => '20', 'q' => 'x']), $grid);

    expect($query->page)->toBe(4)
        ->and($query->perPage)->toBe(20)
        ->and($query->sort)->toBe(['key' => 'name', 'direction' => 'desc'])
        ->and($query->q)->toBe('x');
});

it('outputs the empty state', function () {
    $full = queryPage(queryGrid()->emptyState('還沒有管理員', '新增第一位管理員後會顯示在這裡。', '新增管理員', '/admin/users/create'));
    $bare = queryPage(queryGrid()->emptyState('還沒有管理員', '之後再來看看。'));

    expect($full['empty'])->toBe([
        'title' => '還沒有管理員',
        'description' => '新增第一位管理員後會顯示在這裡。',
        'action' => ['label' => '新增管理員', 'url' => '/admin/users/create'],
    ])
        ->and($bare['empty'])->toBe(['title' => '還沒有管理員', 'description' => '之後再來看看。'])
        ->and(queryPage(queryGrid()))->not->toHaveKey('empty');
});

it('needs both the label and the url for an empty-state action', function () {
    queryGrid()->emptyState('t', 'd', '新增');
})->throws(InvalidArgumentException::class);

it('outputs the column options', function () {
    $grid = Grid::make(User::class);
    $grid->column('username')->sortable()->width('12rem')->primary();
    $grid->column('name')->hideable(false);
    $grid->column('avatar')->hidden();
    $grid->column('created_at')->hideable();

    expect(queryPage($grid)['columns'])->toBe([
        ['key' => 'username', 'label' => 'Username', 'width' => '12rem', 'sortable' => true, 'primary' => true],
        ['key' => 'name', 'label' => 'Name', 'hideable' => false],
        ['key' => 'avatar', 'label' => 'Avatar', 'hiddenByDefault' => true],
        ['key' => 'created_at', 'label' => 'Created At'],
    ]);
});

it('uses the route name as the grid id, then the path, unless set', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::get('/grid-named', fn () => queryGrid())->name('grids.named');
        Route::get('/grid-unnamed', fn () => queryGrid());
        Route::get('/grid-custom', fn () => queryGrid()->id('admins'));
    });

    $gridId = fn (string $uri) => $this->get($uri)->viewData('page')['props']['envelope']['page']['gridId'];

    expect($gridId('/grid-named'))->toBe('grids.named')
        ->and($gridId('/grid-unnamed'))->toBe('grid-unnamed')
        ->and($gridId('/grid-custom'))->toBe('admins');
});

it('outputs empty applied filters and actions, and no endpoints off a brumeGrid route', function () {
    $page = queryPage(queryGrid());

    expect($page['appliedFilters'])->toBe([])
        ->and($page['actions'])->toBe(['row' => [], 'batch' => [], 'tools' => []])
        ->and($page)->not->toHaveKey('endpoints');
});

it('escapes LIKE terms and lowers both sides', function () {
    DB::enableQueryLog();

    foreach ([
        fn ($query) => Like::contains($query, 'name', 'a!b%c_d'),
        fn ($query) => Like::startsWith($query, 'name', 'x', caseInsensitive: false),
        fn ($query) => Like::endsWith($query->where('id', 1), 'name', 'y', boolean: 'or'),
    ] as $apply) {
        $query = User::query();
        $apply($query);
        $query->get();
    }

    $log = array_map(fn ($entry) => ['query' => portableSql($entry['query'])] + $entry, DB::getQueryLog());

    expect($log[0]['query'])->toBe('select * from "brume_users" where lower("name") like lower(?) escape \'!\'')
        ->and($log[0]['bindings'])->toBe(['%a!!b!%c!_d%'])
        ->and($log[1]['query'])->toBe('select * from "brume_users" where "name" like ? escape \'!\'')
        ->and($log[1]['bindings'])->toBe(['x%'])
        ->and($log[2]['query'])->toBe('select * from "brume_users" where "id" = ? or lower("name") like lower(?) escape \'!\'')
        ->and($log[2]['bindings'])->toBe([1, '%y']);
});
