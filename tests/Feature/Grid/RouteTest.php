<?php

use Brume\Grid\Grid;
use Brume\Grid\GridResolver;
use Brume\Http\Controllers\GridEndpointController;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Tests\Support\Controllers\NotAGridController;
use Brume\Tests\Support\Controllers\OrderGridController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

/**
 * Register routes the way a project's routes/brume.php is loaded: under /admin with Brume's middleware.
 */
function adminRoutes(Closure $routes): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])
        ->group($routes);

    Route::getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
});

it('registers the page route and the four endpoints', function () {
    $route = null;
    adminRoutes(function () use (&$route) {
        $route = Route::brumeGrid('orders', OrderGridController::class)->name('brume.orders.index');
    });

    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin/orders'))
        ->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())
        ->values()
        ->all();

    expect($registered)->toEqualCanonicalizing([
        'GET admin/orders',
        'POST admin/orders/_brume/actions/{action}',
        'PATCH admin/orders/_brume/cells/{id}',
        'GET admin/orders/_brume/export',
        'GET admin/orders/_brume/options/{source}',
    ])
        ->and($route->getName())->toBe('brume.orders.index')
        ->and(Route::getRoutes()->getByName('brume.orders.index')->uri())->toBe('admin/orders')
        ->and(route('brume.orders.index', absolute: false))->toBe('/admin/orders')
        ->and($route->defaults['_brume_grid'])->toBeTrue();
});

it('outputs the endpoints on a brumeGrid page', function () {
    Order::factory()->count(2)->create();
    adminRoutes(fn () => Route::brumeGrid('orders', OrderGridController::class)->name('brume.orders.index'));

    $this->get('/admin/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->where('envelope.page.gridId', 'brume.orders.index')
        ->where('envelope.page.endpoints', [
            'action' => '/admin/orders/_brume/actions/{action}',
            'cell' => '/admin/orders/_brume/cells/{id}',
            'options' => '/admin/orders/_brume/options/{source}',
        ])
        ->has('envelope.page.rows', 2));
});

it('leaves endpoints out on a plain Route::get page', function () {
    adminRoutes(fn () => Route::get('orders', OrderGridController::class));

    $this->get('/admin/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->missing('envelope.page.endpoints'));
});

it('accepts a controller method and a closure as the action', function (Closure $action, string $title) {
    adminRoutes(fn () => Route::brumeGrid('orders', $action()));

    $this->get('/admin/orders')->assertOk()->assertInertia(fn ($page) => $page->where('envelope.page.title', $title));
    $this->postJson('/admin/orders/_brume/actions/x', ['target' => 'row', 'ids' => [1]])->assertNotFound();
})->with([
    'array' => [fn () => [OrderGridController::class, 'index'], 'Orders (index)'],
    'Class@method' => [fn () => OrderGridController::class.'@index', 'Orders (index)'],
    'closure' => [fn () => fn () => Grid::make(Order::class)->title('Closure orders'), 'Closure orders'],
]);

it('answers the endpoints with a JSON 404 until the grid declares the feature', function (string $method, string $uri) {
    adminRoutes(fn () => Route::brumeGrid('orders', OrderGridController::class));

    $this->json($method, $uri, ['target' => 'row', 'ids' => [1], 'column' => 'customer', 'value' => 'x'])
        ->assertNotFound()
        ->assertJsonStructure(['message']);
})->with([
    'action' => ['POST', '/admin/orders/_brume/actions/ship'],
    'cell' => ['PATCH', '/admin/orders/_brume/cells/1'],
    'export' => ['GET', '/admin/orders/_brume/export'],
    'options' => ['GET', '/admin/orders/_brume/options/customer'],
]);

it('fails loudly when the controller does not return a Grid', function () {
    $this->withoutExceptionHandling();
    adminRoutes(fn () => Route::brumeGrid('broken', NotAGridController::class));

    $this->postJson('/admin/broken/_brume/actions/x', ['target' => 'row', 'ids' => [1]]);
})->throws(LogicException::class, '必須回傳 Grid');

it('names the action and the returned type when resolving fails', function () {
    GridResolver::resolve(NotAGridController::class, Request::create('/'));
})->throws(LogicException::class, NotAGridController::class);

it('passes the route parameters to the controller from the endpoints', function () {
    $seen = [];
    // Not an arrow function: it would capture $seen by value.
    adminRoutes(function () use (&$seen) {
        Route::brumeGrid('shops/{shop}/orders', function (string $shop) use (&$seen) {
            $seen[] = $shop;

            return Grid::make(Order::query());
        });
    });

    $this->get('/admin/shops/5/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->where('envelope.page.endpoints.action', '/admin/shops/5/orders/_brume/actions/{action}'));
    $this->postJson('/admin/shops/7/orders/_brume/actions/x', ['target' => 'row', 'ids' => [1]])->assertNotFound();
    $this->patchJson('/admin/shops/8/orders/_brume/cells/3', ['column' => 'customer', 'value' => 'x'])->assertNotFound();
    $this->getJson('/admin/shops/9/orders/_brume/options/customer')->assertNotFound();

    expect($seen)->toBe(['5', '7', '8', '9']);
});

it('binds route models for the controller behind the endpoints', function () {
    $owner = brumeUser(['username' => 'owner']);
    $seen = null;
    adminRoutes(function () use (&$seen) {
        Route::brumeGrid('users/{user}/orders', function (User $user) use (&$seen) {
            $seen = $user;

            return Grid::make(Order::query());
        });
    });

    $this->getJson("/admin/users/{$owner->getKey()}/orders/_brume/export")->assertNotFound();

    expect($seen)->toBeInstanceOf(User::class)
        ->and($seen->is($owner))->toBeTrue();
});

it('routes the endpoints to GridEndpointController', function () {
    adminRoutes(fn () => Route::brumeGrid('orders', OrderGridController::class));

    $action = Route::getRoutes()->match(Request::create('/admin/orders/_brume/actions/ship', 'POST'));

    expect($action->getActionName())->toBe(GridEndpointController::class.'@action')
        ->and($action->defaults['_brume_grid_action'])->toBe(OrderGridController::class);
});
