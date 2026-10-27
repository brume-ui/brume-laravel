<?php

namespace Workbench\App\Providers;

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\workbench_path;

/**
 * Turns the workbench into a demo admin (menu, dashboard, 管理員 list) when it runs as a dev
 * server. Pest boots this provider too but keeps the package defaults, so it does nothing there.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        foreach (require workbench_path('config', 'brume.php') as $key => $value) {
            config(["brume.{$key}" => $value]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $this->loadTranslationsFrom(workbench_path('lang'), 'workbench');

        // Same group Brume puts a project's routes/brume.php in (BrumeServiceProvider::registerRoutes);
        // testbench's base_path() is its own skeleton, so Brume cannot find this file by itself.
        Route::group([
            'prefix' => config('brume.route.prefix'),
            'domain' => config('brume.route.domain'),
            'middleware' => [...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'],
        ], workbench_path('routes', 'brume.php'));
    }
}
