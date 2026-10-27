<?php

use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function seedUsers(int $n): void
{
    foreach (range(1, $n) as $i) {
        User::query()->create(['username' => "user{$i}", 'password' => 'secret', 'name' => "Name {$i}"]);
    }
}

function userGrid(): Grid
{
    $grid = Grid::make(User::query()->orderBy('id'))->title('Users')->perPage(10);
    $grid->column('username');
    $grid->column('name', 'Display name');

    return $grid;
}

function schemaFor(Grid $grid, array $query = []): array
{
    return $grid->toSchema(Request::create('/admin/users', 'GET', $query));
}

it('reports pagination for the first page', function () {
    seedUsers(25);

    $schema = schemaFor(userGrid());

    expect($schema['type'])->toBe('list')
        ->and($schema['pagination'])->toBe(['page' => 1, 'perPage' => 10, 'total' => 25, 'lastPage' => 3])
        ->and($schema['rows'])->toHaveCount(10);
});

it('page param is clamped', function (mixed $page, int $expectedPage, string $firstUsername) {
    seedUsers(25);
    $this->actingAs(brumeUser(['username' => 'admin']), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->get('/grid-clamp', fn () => userGrid());

    $response = $this->get('/grid-clamp?page='.$page)->assertOk();
    $envelope = $response->viewData('page')['props']['envelope']['page'];

    expect($envelope['pagination']['page'])->toBe($expectedPage)
        ->and($envelope['rows'][0]['username'])->toBe($firstUsername);
})->with([
    'beyond last' => ['999', 3, 'user21'],
    'huge number' => ['99999999999999999999999', 3, 'user21'],
    'not a number' => ['abc', 1, 'user1'],
    'negative' => ['-1', 1, 'user1'],
    'zero' => ['0', 1, 'user1'],
    'decimal' => ['2.5', 1, 'user1'],
]);

it('clamps an array page param to page 1', function () {
    seedUsers(3);

    expect(schemaFor(userGrid(), ['page' => ['2']])['pagination']['page'])->toBe(1);
});

it('reports lastPage 1 for an empty list', function () {
    $schema = schemaFor(userGrid(), ['page' => '5']);

    expect($schema['rows'])->toBe([])
        ->and($schema['pagination'])->toBe(['page' => 1, 'perPage' => 10, 'total' => 0, 'lastPage' => 1]);
});

it('outputs only id and declared columns', function () {
    seedUsers(1);

    $row = schemaFor(userGrid())['rows'][0];

    expect(array_keys($row))->toBe(['id', 'username', 'name'])
        ->and($row)->not->toHaveKey('password')
        ->and($row)->not->toHaveKey('remember_token');
});

it('defaults the label to a headline and honours numeric', function () {
    $grid = Grid::make(User::class);
    $grid->column('created_at');
    $grid->column('id')->numeric();

    expect(schemaFor($grid)['columns'])->toBe([
        ['key' => 'created_at', 'label' => 'Created At'],
        ['key' => 'id', 'label' => 'Id', 'numeric' => true],
    ]);
});

it('applies the display closure with value and row', function () {
    seedUsers(1);
    $grid = Grid::make(User::query());
    $grid->column('name')->display(fn ($value, $row) => strtoupper($value).'#'.$row->username);

    expect(schemaFor($grid)['rows'][0]['name'])->toBe('NAME 1#user1');
});

it('emits dates as ISO-8601 and keeps null', function () {
    seedUsers(1);
    $grid = Grid::make(User::query());
    $grid->column('created_at');
    $grid->column('avatar');

    $row = schemaFor($grid)['rows'][0];

    expect($row['created_at'])->toBe(User::query()->first()->created_at->format(DATE_ATOM))
        ->and($row['avatar'])->toBeNull();
});

it('supports dot notation through data_get', function () {
    seedUsers(1);
    $grid = Grid::make(User::query());
    $grid->column('profile.name')->display(fn ($value, $row) => $value ?? 'none');
    $grid->column('name');

    expect(schemaFor($grid)['rows'][0])->toHaveKey('profile.name', 'none');
});

it('emits a component ref and leaves the raw value in rows', function () {
    seedUsers(1);
    $grid = Grid::make(User::query());
    $grid->column('name')->component('status-badge', ['tone' => 'success']);
    $grid->column('username')->component('plain');

    $schema = schemaFor($grid);

    expect($schema['columns'][0]['display'])->toBe(['type' => 'component', 'name' => 'status-badge', 'props' => ['tone' => 'success']])
        ->and($schema['columns'][1]['display'])->toBe(['type' => 'component', 'name' => 'plain'])
        ->and($schema['rows'][0]['name'])->toBe('Name 1');
});

it('rejects a props list', function () {
    Grid::make(User::query())->column('name')->component('x', ['a', 'b']);
})->throws(InvalidArgumentException::class);

it('can be returned straight from a controller', function () {
    seedUsers(2);
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->get('/grid-direct', fn () => userGrid());

    $this->get('/grid-direct')->assertOk()->assertInertia(fn ($page) => $page
        ->component('brume/page')
        ->where('envelope.schemaVersion', '0.1.0')
        ->where('envelope.page.type', 'list')
        ->where('envelope.page.title', 'Users'));
});

it('rejects a display closure that returns a non-scalar', function (mixed $returned) {
    seedUsers(1);
    $grid = Grid::make(User::query());
    $grid->column('name')->display(fn () => $returned);

    schemaFor($grid);
})->with([
    'array' => [[1, 2]],
    'object' => [new stdClass],
])->throws(InvalidArgumentException::class, 'name');

it('accepts null, bool, int, float and string from a display closure', function () {
    seedUsers(1);
    $grid = Grid::make(User::query());
    foreach ([null, true, 3, 1.5, 'x'] as $i => $value) {
        $grid->column("c{$i}")->display(fn () => $value);
    }

    expect(array_values(array_slice(schemaFor($grid)['rows'][0], 1)))->toBe([null, true, 3, 1.5, 'x']);
});

it('orders by the qualified primary key when the query has no order', function () {
    seedUsers(3);
    DB::enableQueryLog();

    $schema = Grid::make(User::class)->toSchema(Request::create('/'));

    $select = collect(DB::getQueryLog())->pluck('query')->first(fn ($sql) => str_contains($sql, 'limit'));
    expect($select)->toContain('order by "brume_users"."id" asc')
        ->and(array_column($schema['rows'], 'id'))->toBe([1, 2, 3]);
});

it('keeps an order the query already has', function () {
    seedUsers(3);
    DB::enableQueryLog();

    $schema = Grid::make(User::query()->orderByDesc('username'))->toSchema(Request::create('/'));

    $select = collect(DB::getQueryLog())->pluck('query')->first(fn ($sql) => str_contains($sql, 'limit'));
    expect($select)->toContain('order by "username" desc')
        ->not->toContain('"brume_users"."id"')
        ->and(array_column($schema['rows'], 'id'))->toBe([3, 2, 1]);
});
