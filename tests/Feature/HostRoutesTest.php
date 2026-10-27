<?php

use Brume\BrumeServiceProvider;
use Brume\Http\Middleware\HandleBrumeRequests;

function loadHostRoutes(string $path): void
{
    config(['brume.routes.host' => $path]);

    $provider = app()->getProvider(BrumeServiceProvider::class);
    (fn () => $this->registerRoutes())->call($provider);
    app('router')->getRoutes()->refreshNameLookups();
}

it('defaults the host routes file to routes/brume.php', function () {
    expect(config('brume.routes.host'))->toBe(base_path('routes/brume.php'));
});

it('loads a custom host routes file inside the brume group, behind brume.auth', function () {
    loadHostRoutes(__DIR__.'/../fixtures/host-routes.php');

    $route = app('router')->getRoutes()->getByName('brume.host-fixture');
    expect($route->uri())->toBe('admin/host-fixture')
        ->and($route->gatherMiddleware())->toContain(HandleBrumeRequests::class, 'brume.auth');

    $this->get('/admin/host-fixture')->assertRedirect('/admin/login');

    $this->actingAs(brumeUser(), 'brume');
    $this->get('/admin/host-fixture')->assertOk()->assertSee('host ok');
});

it('skips a host routes file that does not exist', function () {
    loadHostRoutes(__DIR__.'/../fixtures/missing-routes.php');

    expect(app('router')->getRoutes()->getByName('brume.host-fixture'))->toBeNull();
});
