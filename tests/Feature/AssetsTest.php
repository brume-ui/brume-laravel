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

it('ships every file the committed manifest references, the lazy editor chunk and its css included', function () {
    $dist = dirname(__DIR__, 2).'/dist';
    $manifest = json_decode(file_get_contents("{$dist}/.vite/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $entry = $manifest[Assets::ENTRY];

    // The entry reaches the editor only through a dynamic import, never through the printed tags.
    $editor = collect($manifest)->first(fn (array $chunk) => str_starts_with(basename($chunk['file']), 'jodit-editor-'));
    expect($editor)->not->toBeNull()
        ->and($editor['isDynamicEntry'] ?? false)->toBeTrue()
        ->and($editor['css'] ?? [])->not->toBeEmpty()
        ->and(array_map(fn (string $key) => $manifest[$key]['file'], $entry['dynamicImports'] ?? []))->toContain($editor['file'])
        ->and(Assets::tags()->toHtml())->not->toContain('jodit');
    // The app chunk imports it relative to its own URL (dist is built with base './'), so it is
    // found next to the entry wherever vendor:publish puts dist/.
    expect(file_get_contents("{$dist}/{$entry['file']}"))->toContain('import(`./'.basename($editor['file']).'`)');

    $files = [];
    foreach ($manifest as $chunk) {
        array_push($files, $chunk['file'], ...($chunk['css'] ?? []), ...($chunk['assets'] ?? []));
        foreach ([...($chunk['imports'] ?? []), ...($chunk['dynamicImports'] ?? [])] as $key) {
            expect($manifest)->toHaveKey($key);
        }
    }
    foreach (array_unique($files) as $file) {
        expect(is_file("{$dist}/{$file}"))->toBeTrue("dist/{$file} is missing");
    }
    expect(is_file("{$dist}/licenses/jodit.txt"))->toBeTrue();
});
