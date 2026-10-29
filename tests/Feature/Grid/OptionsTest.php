<?php

use Brume\Grid\Grid;
use Brume\Grid\Options\RemoteOptions;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

/**
 * /admin/orders with a remote 客戶 filter over the Brume users (value: id, label: name), behind
 * brume.auth as a project's routes/brume.php is.
 */
function optionsRoutes(?Closure $declare = null): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('orders', function () use ($declare) {
            $grid = Grid::make(Order::class)->title('Orders');
            $grid->column('customer');
            $grid->filters()->equal('customer', '客戶')->remoteSelect(User::class, 'name', searchColumns: ['name', 'username']);
            $grid->filters()->equal('status', '狀態')->select(['paid' => '已付款']);

            if ($declare) {
                $declare($grid);
            }

            return $grid;
        }));
}

/**
 * Users "Ada 02" .. "Ada {n}" after the signed-in "Admin" (user 1), then "Zed" (user n + 1).
 */
function optionsUsers(int $count): void
{
    User::query()->insert(array_map(fn (int $i) => [
        'username' => sprintf('u%02d', $i), 'password' => 'x', 'name' => sprintf('Ada %02d', $i), 'created_at' => now(), 'updated_at' => now(),
    ], range(2, $count)));
    User::query()->create(['username' => 'zed', 'password' => 'x', 'name' => 'Zed']);
}

/**
 * The id of the $n-th user this test created, as an option value: MySQL keeps counting ids
 * across rolled-back tests.
 */
function optionsId(int $n): string
{
    return (string) (User::query()->min('id') - 1 + $n);
}

beforeEach(function () {
    $this->actingAs(brumeUser(['name' => 'Admin']), 'brume');
});

it('answers a page of options as an OptionsResponse', function () {
    optionsUsers(13);
    optionsRoutes();

    $response = $this->getJson('/admin/orders/_brume/options/customer?q=ad&page=2&per_page=5')->assertOk();

    // Ada 02..13 and "Admin" (id 1) match "ad": 13 options ordered by label, so page 3 has 3.
    expect(JsonSchema::errors($response->json(), 'OptionsResponse'))->toBe([])
        ->and($response->json('data'))->toBe(array_map(
            fn (int $i) => ['value' => optionsId($i), 'label' => sprintf('Ada %02d', $i)],
            range(7, 11),
        ))
        ->and($response->json('pagination'))->toBe(['page' => 2, 'perPage' => 5, 'hasMore' => true])
        ->and($this->getJson('/admin/orders/_brume/options/customer?q=ad&page=3&per_page=5')->json('pagination.hasMore'))->toBeFalse()
        ->and($this->getJson('/admin/orders/_brume/options/customer?q=ad&page=3&per_page=5')->json('data.2.label'))->toBe('Admin')
        ->and($this->getJson('/admin/orders/_brume/options/customer?q=ZED')->json('data'))->toBe([['value' => optionsId(14), 'label' => 'Zed']])
        ->and($this->getJson('/admin/orders/_brume/options/customer?q=u03')->json('data.0.label'))->toBe('Ada 03');
});

it('turns value[] back into labels', function () {
    optionsUsers(5);
    optionsRoutes();

    $response = $this->getJson('/admin/orders/_brume/options/customer?value[]='.optionsId(3))->assertOk();

    expect($response->json())->toBe(['data' => [['value' => optionsId(3), 'label' => 'Ada 03']], 'pagination' => ['page' => 1, 'perPage' => 1, 'hasMore' => false]])
        ->and(JsonSchema::errors($response->json(), 'OptionsResponse'))->toBe([])
        ->and($this->getJson('/admin/orders/_brume/options/customer?'.http_build_query(['value' => [optionsId(4), '999999', optionsId(2)]]))->json('data'))
        ->toBe([['value' => optionsId(4), 'label' => 'Ada 04'], ['value' => optionsId(2), 'label' => 'Ada 02']]);
});

it('resolves at most 100 values', function () {
    optionsUsers(3);
    optionsRoutes();
    $values = [...array_map(fn (int $i) => "missing-{$i}", range(1, RemoteOptions::MAX_VALUES)), optionsId(2)];

    $response = $this->getJson('/admin/orders/_brume/options/customer?'.http_build_query(['value' => $values]))->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('pagination.perPage'))->toBe(RemoteOptions::MAX_VALUES);
});

it('answers an unknown or non-remote source with a 404 ErrorResponse', function (string $source) {
    optionsRoutes();

    $response = $this->getJson("/admin/orders/_brume/options/{$source}")->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
})->with(['unknown' => ['nope'], 'a local select' => ['status']]);

it('caps per_page at 50', function () {
    optionsUsers(60);
    optionsRoutes();

    $response = $this->getJson('/admin/orders/_brume/options/customer?per_page=1000')->assertOk();

    expect($response->json('pagination'))->toBe(['page' => 1, 'perPage' => 50, 'hasMore' => true])
        ->and($response->json('data'))->toHaveCount(50);
});

it('falls back to the defaults for invalid paging', function (array $query) {
    optionsUsers(3);
    optionsRoutes();

    expect($this->getJson('/admin/orders/_brume/options/customer?'.http_build_query($query))->assertOk()->json('pagination'))
        ->toBe(['page' => 1, 'perPage' => RemoteOptions::DEFAULT_PER_PAGE, 'hasMore' => false]);
})->with([
    'not numbers' => [['page' => 'x', 'per_page' => 'y']],
    'zero and negative' => [['page' => '0', 'per_page' => '-5']],
    'arrays' => [['page' => ['2'], 'per_page' => ['5'], 'q' => ['a']]],
]);

it('answers 401 JSON, not a redirect, when signed out', function () {
    optionsRoutes();
    auth('brume')->logout();

    $response = $this->getJson('/admin/orders/_brume/options/customer')->assertUnauthorized();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($this->get('/admin/orders')->assertRedirect()->headers->get('Location'))->toEndWith('/admin/login');
});

it('searches a query builder and a custom value column', function () {
    optionsUsers(4);
    optionsRoutes(fn (Grid $grid) => $grid->filters()->in('owner', '負責人', 'customer')
        ->remoteMultipleSelect(User::query()->where('username', '!=', 'u03'), 'name', 'username'));

    expect($this->getJson('/admin/orders/_brume/options/owner?q=ada')->json('data'))->toBe([
        ['value' => 'u02', 'label' => 'Ada 02'],
        ['value' => 'u04', 'label' => 'Ada 04'],
    ]);
});

it('outputs a remote input that needs the brumeGrid options endpoint', function () {
    optionsRoutes();

    $page = $this->get('/admin/orders')->assertOk()->viewData('page')['props']['envelope']['page'];

    expect($page['filters']['items'][0]['input'])->toBe(['type' => 'select', 'remote' => true])
        ->and($page['endpoints']['options'])->toBe('/admin/orders/_brume/options/{source}');
});

it('requires Route::brumeGrid for a remote filter in local', function () {
    app()->detectEnvironment(fn () => 'local');
    $grid = Grid::make(Order::class)->title('訂單');
    $grid->filters()->equal('customer', '客戶')->remoteSelect(User::class, 'name');

    $grid->toSchema(Request::create('/admin/orders'));
})->throws(LogicException::class, 'Route::brumeGrid');

it('refuses a hidden attribute as the label, value or a search column', function (array $args) {
    RemoteOptions::make(User::class, ...$args);
})->with([
    'label' => [['password']],
    'value' => [['name', 'remember_token']],
    'search' => [['name', null, ['name', 'password']]],
])->throws(InvalidArgumentException::class, '的 $hidden 裡');
