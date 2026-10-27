<?php

use Brume\Support\Assets;
use Brume\Version;

$root = dirname(__DIR__, 2);

it('pins every brume-ui package in frontend/package.json to Version::UI', function () use ($root) {
    $package = json_decode(file_get_contents($root.'/frontend/package.json'), true, 512, JSON_THROW_ON_ERROR);
    $dependencies = [...$package['dependencies'] ?? [], ...$package['devDependencies'] ?? []];

    foreach (['tokens', 'schema', 'react', 'renderer', 'inertia'] as $name) {
        expect($dependencies)->toHaveKey("@herb09638/brume-{$name}")
            ->and($dependencies["@herb09638/brume-{$name}"])->toBe(Version::UI);
    }
});

it('ships a precompiled build whose manifest has the entry Assets loads', function () use ($root) {
    $manifest = json_decode(file_get_contents($root.'/dist/.vite/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKey(Assets::ENTRY);

    $entry = $manifest[Assets::ENTRY];

    expect($entry['css'] ?? [])->not->toBeEmpty();

    foreach ([$entry['file'], ...$entry['css']] as $file) {
        expect($root.'/dist/'.$file)->toBeFile();
    }
});

it('references fonts relative to the built css so ASSET_URL and subpaths keep working', function () use ($root) {
    $manifest = json_decode(file_get_contents($root.'/dist/.vite/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest[Assets::ENTRY]['css'] as $css) {
        $content = file_get_contents($root.'/dist/'.$css);

        expect(str_contains($content, 'url(./'))->toBeTrue("{$css} has no relative url()")
            ->and(preg_match('/url\(\s*["\']?\//', $content))->toBe(0, "{$css} has an absolute url(/…)");
    }
});
