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

    'uploads' => [
        // null inspects the default connection; add and migrate every other upload model connection.
        'connections' => null,
        'disk' => env('BRUME_UPLOAD_DISK', 'public'),
        'temp_disk' => 'local',
        'temp_ttl' => 1440,
        'max_size' => 10240,
        'schedule_cleanup' => true,
        'safe_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'csv', 'zip', 'gz', 'tar', '7z', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'mp3', 'mp4', 'wav', 'ogg', 'webm'],
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
