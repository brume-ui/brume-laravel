<?php

namespace Brume;

use Brume\Http\Middleware\Authenticate;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Http\Middleware\RedirectIfAuthenticated;
use Brume\Support\Color;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\ServiceProvider as InertiaServiceProvider;
use RuntimeException;

class BrumeServiceProvider extends ServiceProvider
{
    /** Primary buttons put white text on the accent, so it needs WCAG AA for normal text. */
    public const MIN_ACCENT_CONTRAST = 4.5;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brume.php', 'brume');

        $this->app->singleton(BrumeManager::class);

        // Brume cannot work without Inertia; register it even when package discovery is off.
        $this->app->register(InertiaServiceProvider::class);

        $this->registerGuard();
    }

    public function boot(): void
    {
        if ($this->app->environment('local')) {
            $this->ensureAccentContrast();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/brume.php' => config_path('brume.php'),
            ], 'brume-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'brume-migrations');

            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/brume'),
            ], 'brume-assets');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'brume');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'brume');

        // The frontend writes this cookie with document.cookie, so it is never encrypted.
        EncryptCookies::except([HandleBrumeRequests::LOCALE_COOKIE]);

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('brume.auth', Authenticate::class);
        $router->aliasMiddleware('brume.guest', RedirectIfAuthenticated::class);

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

    protected function ensureAccentContrast(): void
    {
        $accent = config('brume.layout.accent');
        $contrast = Color::contrast('#ffffff', $accent);

        if ($contrast < self::MIN_ACCENT_CONTRAST) {
            throw new RuntimeException(sprintf(
                'Brume 主色 %s 和白色文字的對比只有 %.2f:1，主要按鈕的文字至少需要 %.1f:1。請在 config/brume.php 的 layout.accent 換一個更深的顏色。',
                $accent,
                $contrast,
                self::MIN_ACCENT_CONTRAST,
            ));
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
            'middleware' => [...config('brume.route.middleware'), HandleBrumeRequests::class],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/brume.php');

            if (file_exists($project = base_path('routes/brume.php'))) {
                Route::middleware('brume.auth')->group($project);
            }
        });
    }
}
