<?php

namespace Workbench\App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
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

        config(['filesystems.disks.brume_demo' => require workbench_path('config', 'filesystems.php')]);

        // `serve` only hands an allow-list of env vars to the PHP server it starts.
        ServeCommand::$passthroughVariables[] = 'WORKBENCH_EMPTY_LIST';
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $this->loadTranslationsFrom(workbench_path('lang'), 'workbench');
        // Unnamespaced too, for lang/{zh_TW,zh_CN}/validation.php: Laravel ships English only.
        $this->app['translation.loader']->addPath(workbench_path('lang'));
    }
}
