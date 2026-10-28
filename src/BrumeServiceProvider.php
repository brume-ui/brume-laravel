<?php

namespace Brume;

use Brume\Console\InstallCommand;
use Brume\Console\PasswordCommand;
use Brume\Console\UserCommand;
use Brume\Grid\GridRouteRegistrar;
use Brume\Http\Controllers\NotFoundController;
use Brume\Http\Middleware\Authenticate;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Http\Middleware\RedirectIfAuthenticated;
use Brume\Support\Assets;
use Brume\Support\Color;
use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
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

        $this->registerGridRoutes();
    }

    public function boot(): void
    {
        $this->ensureValidAccent();

        if ($this->app->environment('local')) {
            $this->ensureAccentContrast();
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                UserCommand::class,
                PasswordCommand::class,
            ]);
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
     * Destinations are resolved against the current base path, so calling this again after
     * the base path changed points every tag at the new project.
     */
    public function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/brume.php' => config_path('brume.php'),
        ], 'brume-config');

        $this->publishes([
            __DIR__.'/../stubs/routes/brume.php' => base_path('routes/brume.php'),
        ], 'brume-routes');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'brume-migrations');

        $this->publishes([
            __DIR__.'/../dist' => public_path(Assets::PUBLISH_DIR),
        ], 'brume-assets');
    }

    /**
     * Route::brumeGrid('orders', OrderController::class): the list page and its `_brume` endpoints.
     */
    protected function registerGridRoutes(): void
    {
        Router::macro('brumeGrid', function (string $uri, string|array|Closure $action): GridRouteRegistrar {
            /** @var Router $this */
            return (new GridRouteRegistrar($this))->register($uri, $action);
        });
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

    /**
     * The accent is written into an inline style, so only #rgb / #rrggbb is allowed. Local fails
     * loudly; other environments log and fall back to the default instead of breaking the panel.
     */
    protected function ensureValidAccent(): void
    {
        $accent = config('brume.layout.accent');

        if (Color::isHex($accent)) {
            return;
        }

        $message = sprintf(
            'Brume 主色「%s」不是有效的色碼。請在 config/brume.php 的 layout.accent 使用 #rrggbb 或 #rgb 格式，例如 %s。',
            is_scalar($accent) ? $accent : get_debug_type($accent),
            Color::DEFAULT_ACCENT,
        );

        if ($this->app->environment('local')) {
            throw new RuntimeException($message);
        }

        Log::warning($message.' 目前先改用預設主色 '.Color::DEFAULT_ACCENT.'。');
        config(['brume.layout.accent' => Color::DEFAULT_ACCENT]);
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

            if (is_string($project = config('brume.routes.host')) && file_exists($project)) {
                Route::middleware('brume.auth')->group($project);
            }

            // Last, after the project's routes: an unknown path under the prefix (GET) answers
            // the styled 404 page to a signed-in user; a guest is sent to the login page. Another
            // method on an unknown path answers 405, because this fallback route is GET only.
            // Without a prefix the fallback would take over every unknown path of the app (and
            // send guests to the admin login), so it is left to Laravel then.
            if (trim((string) config('brume.route.prefix'), '/') !== '') {
                Route::fallback(NotFoundController::class)->middleware('brume.auth');
            }
        });
    }
}
