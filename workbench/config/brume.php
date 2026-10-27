<?php

use Workbench\App\Http\Controllers\DashboardController;

use function Orchestra\Testbench\workbench_path;

// Overrides for the package's config/brume.php, applied by WorkbenchServiceProvider when the
// workbench runs as a dev server (testbench serve). Pest keeps the package defaults.
return [
    'home' => DashboardController::class,

    // testbench's base_path() is its own skeleton, so point Brume at the workbench routes.
    'routes' => [
        'host' => workbench_path('routes', 'brume.php'),
    ],

    'menu' => [
        ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
        ['title' => 'workbench::menu.system', 'icon' => 'settings', 'children' => [
            ['title' => 'workbench::menu.admins', 'icon' => 'users', 'url' => '/users'],
        ]],
    ],
];
