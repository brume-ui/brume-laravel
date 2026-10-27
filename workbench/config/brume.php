<?php

use Workbench\App\Http\Controllers\DashboardController;

// Overrides for the package's config/brume.php, applied by WorkbenchServiceProvider when the
// workbench runs as a dev server (testbench serve). Pest keeps the package defaults.
return [
    'home' => DashboardController::class,

    'menu' => [
        ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
        ['title' => 'workbench::menu.system', 'icon' => 'settings', 'children' => [
            ['title' => 'workbench::menu.admins', 'icon' => 'users', 'url' => '/users'],
        ]],
    ],
];
