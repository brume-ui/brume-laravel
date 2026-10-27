<?php

use Brume\Support\Assets;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(public_path('brume.hot'));
    File::deleteDirectory(public_path('vendor/brume'));
});

it('prints the precompiled css and module script under /vendor/brume/', function () {
    $html = Assets::tags()->toHtml();

    preg_match('/<script type="module" src="([^"]+)"/', $html, $script);
    preg_match('/<link rel="stylesheet" href="([^"]+)"/', $html, $style);

    expect(parse_url($script[1], PHP_URL_PATH))->toBe('/vendor/brume/assets/app-B1x2y3z4.js')
        ->and(parse_url($style[1], PHP_URL_PATH))->toBe('/vendor/brume/assets/app-C5d6e7f8.css');
});

it('hashes the manifest as the asset version, null without a manifest', function () {
    expect(Assets::version())->toBe(md5_file(Assets::$manifestPath));

    Assets::$manifestPath = '/nonexistent/manifest.json';

    expect(Assets::version())->toBeNull();
});

it('points at the vite dev server when the hot file exists in vite mode', function () {
    config(['brume.frontend.mode' => 'vite']);
    File::put(public_path('brume.hot'), 'http://localhost:5173');

    $html = Assets::tags()->toHtml();

    expect($html)->toContain('http://localhost:5173/@vite/client')
        ->and($html)->toContain('http://localhost:5173/resources/js/brume/app.tsx');
});

it('tells the developer to publish assets when they are missing in local', function () {
    app()->detectEnvironment(fn () => 'local');

    Assets::tags();
})->throws(RuntimeException::class, 'php artisan vendor:publish --tag=brume-assets --force');

it('tells the developer to republish when the published assets are stale in local', function () {
    app()->detectEnvironment(fn () => 'local');
    File::ensureDirectoryExists(public_path('vendor/brume/.vite'));
    File::put(public_path('vendor/brume/.vite/manifest.json'), '{"old": true}');

    Assets::tags();
})->throws(RuntimeException::class, 'vendor:publish --tag=brume-assets');

it('accepts published assets that match the package manifest in local', function () {
    app()->detectEnvironment(fn () => 'local');
    File::ensureDirectoryExists(public_path('vendor/brume/.vite'));
    File::copy(Assets::$manifestPath, public_path('vendor/brume/.vite/manifest.json'));

    expect(Assets::tags()->toHtml())->toContain('/vendor/brume/assets/app-B1x2y3z4.js');
});
