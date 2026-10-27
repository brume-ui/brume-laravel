<?php

namespace Brume;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BrumeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brume.php', 'brume');

        $this->registerGuard();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/brume.php' => config_path('brume.php'),
            ], 'brume-config');
        }

        $this->registerRoutes();
    }

    /**
     * Register the brume guard and user provider so projects do not have to edit config/auth.php.
     * Existing entries (e.g. a project overriding the guard) are left untouched.
     */
    protected function registerGuard(): void
    {
        $guard = config('brume.auth.guard');

        if (config("auth.guards.{$guard}") === null) {
            config(["auth.guards.{$guard}" => [
                'driver' => 'session',
                'provider' => $guard,
            ]]);
        }

        if (config("auth.providers.{$guard}") === null) {
            config(["auth.providers.{$guard}" => [
                'driver' => 'eloquent',
                'model' => config('brume.auth.model'),
            ]]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix' => config('brume.route.prefix'),
            'domain' => config('brume.route.domain'),
            'middleware' => config('brume.route.middleware'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/brume.php');

            if (file_exists($project = base_path('routes/brume.php'))) {
                $this->loadRoutesFrom($project);
            }
        });
    }
}
