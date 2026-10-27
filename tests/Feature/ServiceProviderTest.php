<?php

use Brume\BrumeServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(BrumeServiceProvider::class))->not->toBeEmpty();
});

it('merges the default config', function () {
    expect(config('brume.route.prefix'))->toBe('admin')
        ->and(config('brume.auth.guard'))->toBe('brume')
        ->and(config('brume.auth.table'))->toBe('brume_users');
});

it('registers the brume guard and provider without touching config/auth.php', function () {
    $guard = config('auth.guards.brume');

    expect($guard)->not->toBeNull()
        ->and($guard['driver'])->toBe('session')
        ->and(config("auth.providers.{$guard['provider']}.model"))->toBe(config('brume.auth.model'));
});

it('ships a workbench .env with isolated session cookie, sqlite and zh_TW', function () {
    $env = file_get_contents(dirname(__DIR__, 2).'/workbench/.env');

    expect($env)->toContain('DB_CONNECTION=sqlite')
        ->toContain('SESSION_COOKIE=brume_workbench_session')
        ->toContain('APP_LOCALE=zh_TW');
});

it('does not overwrite a guard and provider the host already defined', function () {
    $app = app();
    $app['config']->set('auth.guards.brume', ['driver' => 'session', 'provider' => 'custom']);
    $app['config']->set('auth.providers.brume', ['driver' => 'eloquent', 'model' => 'App\\Models\\Admin']);

    (new BrumeServiceProvider($app))->register();

    expect(config('auth.guards.brume.provider'))->toBe('custom')
        ->and(config('auth.providers.brume.model'))->toBe('App\\Models\\Admin');
});
