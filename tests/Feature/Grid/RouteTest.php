<?php

use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Grid\GridRouteRegistrar;
use Brume\Http\Controllers\GridEndpointController;
use Brume\Http\Middleware\Authenticate;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Http\Middleware\HandleGridEndpoint;
use Brume\Models\User;
use Brume\Tests\Support\Controllers\GuardedOrderGridController;
use Brume\Tests\Support\Controllers\LegacyGuardedOrderGridController;
use Brume\Tests\Support\Controllers\NotAGridController;
use Brume\Tests\Support\Controllers\OrderGridController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        'POST admin/orders/_brume/actions/{_brume_action}',
        'PATCH admin/orders/_brume/cells/{_brume_id}',
        'GET admin/orders/_brume/export',
        'GET admin/orders/_brume/options/{_brume_source}',
    ])
        ->and($route)->toBeInstanceOf(GridRouteRegistrar::class)
        ->and($route->getName())->toBe('brume.orders.index')
        ->and(Route::getRoutes()->getByName('brume.orders.index')->uri())->toBe('admin/orders')
        ->and(route('brume.orders.index', absolute: false))->toBe('/admin/orders')
        ->and($route->page()->getAction('brume_grid'))->toBe('page')
        // Markers live in the route action, not in defaults, which Laravel would pass to the controller.
        ->and($route->page()->defaults)->toBe([]);

    foreach ($route->endpoints() as $name => $endpoint) {
        expect($endpoint->getAction('brume_grid'))->toBe($name)
            ->and($endpoint->getActionName())->toBe(OrderGridController::class)
            ->and(last($endpoint->middleware()))->toBe(HandleGridEndpoint::class);
    }
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

it('names the action in the not-a-Grid error', function () {
    $this->withoutExceptionHandling();
    adminRoutes(fn () => Route::brumeGrid('broken', NotAGridController::class));

    $this->getJson('/admin/broken/_brume/export');
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

it('gives page parameters named id, action or source to the page action', function () {
    $seen = [];
    adminRoutes(function () use (&$seen) {
        Route::brumeGrid('shops/{id}/orders/{source}/{action}', function (string $id, string $source, string $action) use (&$seen) {
            $seen[] = [$id, $source, $action];

            return Grid::make(Order::query());
        });
    });

    $this->get('/admin/shops/1/orders/web/list')->assertOk()->assertInertia(fn ($page) => $page
        ->where('envelope.page.endpoints.cell', '/admin/shops/1/orders/web/list/_brume/cells/{id}'));
    $this->postJson('/admin/shops/2/orders/web/list/_brume/actions/ship', ['target' => 'row', 'ids' => [1]])->assertNotFound();
    $this->patchJson('/admin/shops/3/orders/web/list/_brume/cells/42', ['column' => 'customer', 'value' => 'x'])->assertNotFound();
    $this->getJson('/admin/shops/4/orders/web/list/_brume/options/customer')->assertNotFound();

    expect($seen)->toBe([['1', 'web', 'list'], ['2', 'web', 'list'], ['3', 'web', 'list'], ['4', 'web', 'list']]);
});

it('hands the endpoint its own parameter', function () {
    adminRoutes(fn () => Route::brumeGrid('shops/{id}/orders', fn () => Grid::make(Order::query())));
    app()->bind(GridEndpointController::class, fn () => new class extends GridEndpointController
    {
        public function cell(Grid $grid, Request $request, string $id): JsonResponse
        {
            app()->instance('test.seen', [$id, $request->route()->parameters()]);

            return parent::cell($grid, $request, $id);
        }
    });

    $this->patchJson('/admin/shops/3/orders/_brume/cells/42', ['column' => 'customer', 'value' => 'x'])->assertNotFound();

    expect(app('test.seen'))->toBe(['42', ['id' => '3']]);
});

it('keeps the endpoints working without middleware or with faked events', function (string $mode) {
    $seen = [];
    $ran = 0;
    adminRoutes(function () use (&$seen, &$ran) {
        Route::brumeGrid('shops/{id}/orders', function (string $id) use (&$seen, &$ran) {
            $seen[] = func_get_args();

            return Grid::make(Order::query())->tools(
                Action::make('sync', '同步')->handle(function () use (&$ran) {
                    $ran++;
                }),
            );
        });
    });

    if ($mode === 'withoutMiddleware') {
        // A host test that disables all middleware: HandleGridEndpoint does not run.
        $this->withoutMiddleware();
    } else {
        // A host test that fakes every event: the RouteMatched listener does not run.
        Event::fake();
    }

    $this->postJson('/admin/shops/3/orders/_brume/actions/sync', ['target' => 'tool', 'ids' => []])->assertOk();

    expect($ran)->toBe(1)
        // The page action gets only the page's parameters, not the action key.
        ->and($seen)->toBe([['3']]);
})->with(['withoutMiddleware', 'Event::fake()']);

it('binds route models with a custom key for the endpoints', function () {
    $owner = brumeUser(['username' => 'owner']);
    $seen = null;
    adminRoutes(function () use (&$seen) {
        Route::brumeGrid('users/{user:username}/orders', function (User $user) use (&$seen) {
            $seen = $user;

            return Grid::make(Order::query());
        });
    });

    $this->getJson('/admin/users/owner/orders/_brume/export')->assertNotFound();

    expect($seen?->is($owner))->toBeTrue();
});

it('applies route modifiers chained on brumeGrid to every endpoint', function () {
    $routes = null;
    adminRoutes(function () use (&$routes) {
        $routes = Route::brumeGrid('users/{user}/orders', fn (User $user) => Grid::make(Order::query()))
            ->middleware('throttle:60,1')
            ->scopeBindings()
            ->withTrashed()
            ->whereNumber('user')
            ->name('brume.user-orders');
    });

    foreach ([$routes->page(), ...array_values($routes->endpoints())] as $route) {
        expect($route->middleware())->toContain('throttle:60,1')
            ->and($route->enforcesScopedBindings())->toBeTrue()
            ->and($route->allowsTrashedBindings())->toBeTrue()
            ->and($route->wheres)->toBe(['user' => '[0-9]+']);
    }

    foreach ($routes->endpoints() as $endpoint) {
        expect(last($endpoint->middleware()))->toBe(HandleGridEndpoint::class);
    }

    expect($routes->page()->getName())->toBe('brume.user-orders')
        ->and(collect($routes->endpoints())->map->getName()->filter()->all())->toBe([]);
});

it('runs route middleware chained on brumeGrid on every endpoint', function (bool $allowed) {
    Gate::define('manage-orders', fn () => $allowed);
    adminRoutes(fn () => Route::brumeGrid('orders', OrderGridController::class)->middleware('can:manage-orders'));

    expectEndpointsGuarded($this, $allowed);
})->with(['denied' => [false], 'allowed' => [true]]);

it('runs the page controller\'s own middleware on every endpoint', function (string $controller, bool $allowed) {
    Gate::define('manage-orders', fn () => $allowed);
    adminRoutes(fn () => Route::brumeGrid('orders', $controller));

    expectEndpointsGuarded($this, $allowed);
})->with([
    'HasMiddleware, denied' => [GuardedOrderGridController::class, false],
    'HasMiddleware, allowed' => [GuardedOrderGridController::class, true],
    '$this->middleware(), denied' => [LegacyGuardedOrderGridController::class, false],
    '$this->middleware(), allowed' => [LegacyGuardedOrderGridController::class, true],
]);

/**
 * The page and the four endpoints answer 403 when denied; allowed, the page renders and the
 * endpoints reach their (still empty) handlers.
 */
function expectEndpointsGuarded($test, bool $allowed): void
{
    $test->get('/admin/orders')->assertStatus($allowed ? 200 : 403);

    foreach ([
        ['POST', '/admin/orders/_brume/actions/ship'],
        ['PATCH', '/admin/orders/_brume/cells/1'],
        ['GET', '/admin/orders/_brume/export'],
        ['GET', '/admin/orders/_brume/options/customer'],
    ] as [$method, $uri]) {
        $test->json($method, $uri, ['target' => 'row', 'ids' => [1]])->assertStatus($allowed ? 404 : 403);
    }
}

it('serves the endpoints of a closure action from cached routes', function () {
    // On its own line: closure serialization reads the source and cannot tell two closures on one line apart.
    $action = function (string $shop) {
        return Grid::make(Order::query())->title("Shop {$shop}");
    };
    adminRoutes(fn () => Route::brumeGrid('shops/{shop}/orders', $action)->middleware('can:manage-orders'));
    Gate::define('manage-orders', fn () => true);

    // What `php artisan route:cache` writes and loads back.
    $routes = Route::getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }
    Route::setCompiledRoutes(eval('return '.var_export($routes->compile(), true).';'));

    $this->get('/admin/shops/5/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->where('envelope.page.title', 'Shop 5')
        ->where('envelope.page.endpoints.action', '/admin/shops/5/orders/_brume/actions/{action}'));
    $this->postJson('/admin/shops/5/orders/_brume/actions/ship', ['target' => 'row', 'ids' => [1]])->assertNotFound()->assertJsonStructure(['message']);

    Gate::define('manage-orders', fn () => false);
    $this->postJson('/admin/shops/5/orders/_brume/actions/ship', ['target' => 'row', 'ids' => [1]])->assertForbidden();
});

it('builds the endpoint URLs under the app\'s base path', function () {
    $route = Route::brumeGrid('admin/orders', OrderGridController::class)->page();
    $request = Request::create('http://localhost/sub/index.php/admin/orders', 'GET', [], [], [], [
        'SCRIPT_FILENAME' => '/var/www/sub/index.php',
        'SCRIPT_NAME' => '/sub/index.php',
    ]);
    $request->setRouteResolver(fn () => $route);

    expect($request->getBaseUrl())->toBe('/sub/index.php')
        ->and((new OrderGridController)()->toSchema($request)['endpoints']['action'])
        ->toBe('/sub/index.php/admin/orders/_brume/actions/{action}');
});

it('applies a domain chained on brumeGrid to every endpoint', function () {
    $routes = Route::brumeGrid('orders', OrderGridController::class)->domain('{tenant}.example.test');

    foreach ([$routes->page(), ...array_values($routes->endpoints())] as $route) {
        expect($route->getDomain())->toBe('{tenant}.example.test');
    }

    expect($routes->domain())->toBe('{tenant}.example.test');
});

it('reads the page route\'s middleware with middleware() and no arguments', function () {
    $routes = Route::brumeGrid('orders', OrderGridController::class)->middleware(['web', 'can:manage-orders']);

    expect($routes->middleware())->toBe(['web', 'can:manage-orders'])
        ->and($routes->middleware())->toBe($routes->page()->middleware());
});

it('does not remember an endpoint (the export) as the page to return to after login', function () {
    adminRoutes(fn () => Route::brumeGrid('orders', fn () => Grid::make(Order::query())->exportable())->middleware('brume.auth'));
    auth('brume')->logout();

    $this->get('/admin/orders/_brume/export')->assertRedirect(route('brume.login'));
    expect(session(Authenticate::INTENDED_KEY))->toBeNull();

    $this->get('/admin/orders')->assertRedirect(route('brume.login'));
    expect(session(Authenticate::INTENDED_KEY))->toBe('http://localhost/admin/orders');
});
