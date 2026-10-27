<?php

use Brume\Http\Controllers\DashboardController;
use Brume\Models\User;

return [
    'name' => env('BRUME_NAME', 'Brume'),

    'route' => [
        'prefix' => 'admin',
        'domain' => null,
        'middleware' => ['web'],
    ],

    'auth' => [
        'guard' => 'brume',
        'model' => User::class,
        'table' => 'brume_users',
    ],

    // 'precompiled' | 'vite'
    'frontend' => [
        'mode' => env('BRUME_FRONTEND', 'precompiled'),
    ],

    'layout' => [
        'menu_position' => 'side',
        'accent' => '#0e7490',
        'sidebar_collapsible' => true,
        'breadcrumbs' => true,
    ],

    'locale' => 'zh_TW',
    'locales' => ['zh_TW', 'en', 'zh_CN'],

    'home' => DashboardController::class,

    'routes' => [
        // The project's admin routes, loaded inside the Brume group (prefix, middleware, brume.auth).
        'host' => base_path('routes/brume.php'),
    ],

    // url is relative to route.prefix
    'menu' => [
        ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
    ],
];
