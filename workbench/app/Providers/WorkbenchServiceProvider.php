<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\workbench_path;

/**
 * Turns the workbench into a demo admin (menu, dashboard, 管理員 list, routes via
 * brume.routes.host) when it runs as a dev server. Pest boots this provider too but keeps the
 * package defaults, so it does nothing there.
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
    }
}
