<?php

use Brume\BrumeServiceProvider;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Pages\ErrorPage;
use Brume\Support\Assets;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Workbench\App\Models\Order;

const NOT_FOUND_PAGE = [
    'type' => 'error',
    'status' => 404,
    'title' => '找不到這個頁面',
    'message' => '網址可能打錯了，或這個頁面已經移除。',
    'action' => ['label' => '回到首頁', 'url' => '/admin'],
];

it('renders an unknown admin path as the styled 404 page inside the shell', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->get('/admin/nope')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('brume/page')
        ->where('envelope.page', NOT_FOUND_PAGE)
        // The shell's shared props are there, so the page renders inside the layout.
        ->has('brume.menu'));

    expect(JsonSchema::errors($response->viewData('page')['props']['envelope'], 'PageEnvelope'))->toBe([]);
});

it('answers an Inertia visit to an unknown admin path with 404 and the page JSON', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->get('/admin/orders/nope/deeper', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Assets::version()])
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true');

    expect($response->json('component'))->toBe('brume/page')
        ->and($response->json('props.envelope.page'))->toBe(NOT_FOUND_PAGE)
        ->and(JsonSchema::errors($response->json('props.envelope'), 'PageEnvelope'))->toBe([]);
});

it('redirects a guest on an unknown admin path to the login page', function () {
    $this->get('/admin/nope')->assertRedirect('/admin/login');
});

it('leaves unknown paths outside the admin prefix to Laravel', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->get('/nope')->assertNotFound();

    expect($response->headers->has('X-Inertia'))->toBeFalse()
        ->and($response->getContent())->not->toContain('brume/page');
});

it('answers an unknown action on a grid endpoint with JSON 404, not the error page', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('orders', fn () => Grid::make(Order::class)->title('Orders')));

    $response = $this->postJson('/admin/orders/_brume/actions/x', ['target' => 'row', 'ids' => [1]])->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json())->not->toHaveKey('component');
});

it('answers a JSON request (not an Inertia visit) to an unknown admin path with ErrorResponse 404', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->getJson('/admin/nope')->assertNotFound();

    expect($response->json())->toBe(['message' => '找不到這個頁面'])
        ->and(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->headers->has('X-Inertia'))->toBeFalse();
});

it('answers another method on an unknown admin path with 405, since the fallback is GET only', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->post('/admin/nope')->assertStatus(405);
    $this->deleteJson('/admin/nope')->assertStatus(405);
});

it('registers no fallback without a route prefix, leaving unknown paths to Laravel', function () {
    config(['brume.route.prefix' => '']);
    app('router')->setRoutes(new RouteCollection);
    (fn () => $this->registerRoutes())->call(app()->getProvider(BrumeServiceProvider::class));
    app('router')->getRoutes()->refreshNameLookups();

    $fallbacks = array_filter(app('router')->getRoutes()->getRoutes(), fn (RoutingRoute $route) => $route->isFallback);
    expect($fallbacks)->toBe([])
        ->and(app('router')->getRoutes()->getByName('brume.login')?->uri())->toBe('login');

    // A guest is not sent to the admin login, and a signed-in user gets Laravel's 404, not the shell.
    $this->get('/nope')->assertNotFound();
    $this->actingAs(brumeUser(), 'brume');
    $response = $this->get('/nope')->assertNotFound();
    expect($response->getContent())->not->toContain('brume/page');
});

it('keeps the fallback without a prefix when Brume has a domain of its own', function () {
    config(['brume.route.prefix' => '', 'brume.route.domain' => 'admin.example.test']);
    app('router')->setRoutes(new RouteCollection);
    (fn () => $this->registerRoutes())->call(app()->getProvider(BrumeServiceProvider::class));
    app('router')->getRoutes()->refreshNameLookups();

    $fallbacks = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn (RoutingRoute $route) => $route->isFallback));
    expect($fallbacks)->toHaveCount(1)
        ->and($fallbacks[0]->getDomain())->toBe('admin.example.test');

    $this->actingAs(brumeUser(), 'brume');
    $this->get('http://admin.example.test/nope')->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('envelope.page.title', '找不到這個頁面'));
    expect($this->get('http://www.example.test/nope')->assertNotFound()->getContent())->not->toContain('brume/page');
});

it('builds the not-found page with the home url under the configured prefix', function () {
    config(['brume.route.prefix' => 'backend']);
    // HandleBrumeRequests sets the locale per request; this builds the page directly.
    app()->setLocale('zh_TW');

    expect(ErrorPage::notFound()->toSchema(Request::create('/')))->toBe(array_replace(NOT_FOUND_PAGE, ['action' => ['label' => '回到首頁', 'url' => '/backend']]));
});
