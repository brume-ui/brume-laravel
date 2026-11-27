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
        ServeCommand::$passthroughVariables[] = 'WORKBENCH_FRONTEND';
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        // 驗證碼示範: a one-character alphabet makes the answer fixed (7777), so the demo and the e2e
        // suite can solve the captcha. Workbench only; the package never touches this config.
        config(['captcha.characters' => ['7'], 'captcha.default.length' => 4]);
        // mews/captcha keeps the expected answer in the default cache store (it calls the Cache facade,
        // there is no store option) between the image and the submit, and the Testbench skeleton's
        // default store (array) forgets it after every request. Side effects of a real store: the
        // login throttle (5 failed attempts per minute) and the 60/min upload throttle become real
        // and survive a restart; e2e/serve.sh clears the cache at startup so reruns begin clean.
        config(['cache.default' => 'file']);

        $this->loadTranslationsFrom(workbench_path('lang'), 'workbench');
        // Unnamespaced too, for lang/{zh_TW,zh_CN}/validation.php: Laravel ships English only.
        $this->app['translation.loader']->addPath(workbench_path('lang'));
    }
}
