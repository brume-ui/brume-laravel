<?php

use Workbench\App\Http\Controllers\DashboardController;

use function Orchestra\Testbench\workbench_path;

// Overrides for the package's config/brume.php, applied by WorkbenchServiceProvider when the
// workbench runs as a dev server (testbench serve). Pest keeps the package defaults.
return [
    // A dotted override preserves the package's MIME allowlist and temporary upload defaults.
    'uploads.disk' => 'brume_demo',

    'home' => DashboardController::class,

    // testbench's base_path() is its own skeleton, so point Brume at the workbench routes.
    'routes' => [
        'host' => workbench_path('routes', 'brume.php'),
    ],

    // Runs like a project installed with `brume:install --frontend`: workbench/resources/js/brume is
    // built by `composer build` (pnpm --dir frontend build:workbench) into public/build-brume.
    'frontend' => [
        'mode' => 'vite',
    ],

    'menu' => [
        ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
        ['title' => 'workbench::menu.orders', 'icon' => 'receipt', 'url' => '/orders'],
        ['title' => 'workbench::menu.grid_showcase', 'icon' => 'table-properties', 'url' => '/grid-showcase'],
        ['title' => 'workbench::menu.compositions', 'icon' => 'layers', 'url' => '/compositions'],
        ['title' => 'workbench::menu.components', 'icon' => 'blocks', 'url' => '/components'],
        ['title' => 'workbench::menu.system', 'icon' => 'settings', 'children' => [
            ['title' => 'workbench::menu.admins', 'icon' => 'users', 'url' => '/users'],
        ]],
    ],
];
