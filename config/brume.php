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

    // Rich text whitelist (HTMLPurifier). Applied when a rich text value is saved and before it is sent to the browser.
    'html' => [
        // true keeps style="position:fixed" (blocks pinned over the whole page).
        'allow_fixed' => false,
        // null allows any class; a list keeps only those classes.
        'allowed_classes' => null,
        // iframe src must match; null allows no iframe at all. The default accepts YouTube /embed/<11-char id> only.
        'iframe_regexp' => '%^(?:https?:)?//(?:www\.)?youtube(?:-nocookie)?\.com/embed/(?!videoseries)[A-Za-z0-9_-]{11}(?:[?#][^\s"<>]*)?$%',
        // Input refused before parsing (HtmlRejected; validate with HtmlSanitizer::limitViolation()). null disables either.
        'max_bytes' => 1_000_000,
        // Element nesting depth; unclosed tags count.
        'max_depth' => 256,
        // Extra values refused for id, input[name] and label[for], added to HtmlSanitizer::RESERVED_NAMES. 'prefix*' matches a prefix.
        'reserved_names' => [],
        // null = storage_path('framework/cache/brume-htmlpurifier'), created on demand; false = no cache.
        'cache_path' => null,
        // callable(HTMLPurifier_Config): void, runs after Brume's settings and before the definition is built.
        // The cached definition is keyed on the hook's source file and lines. If its behaviour depends on
        // anything else (closure `use` variables, env, database), set cache_path to false or change
        // configure_version whenever that behaviour changes.
        'configure' => null,
        'configure_version' => null,
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
