<?php

namespace Workbench\App\Http\Controllers;

use Brume\Dashboard\Dashboard;

/**
 * Workbench home (config brume.home). The demo-chart block is not registered in the precompiled
 * frontend on purpose: it shows the "use --frontend" guidance instead of an empty slot.
 */
class DashboardController
{
    public function __invoke(): Dashboard
    {
        return Dashboard::make('儀表板')
            ->card('歡迎使用 Brume', '這是 workbench 的示範後台。從左側選單「系統 → 管理員」可以看到列表與分頁。')
            ->component('demo-chart');
    }
}
