<?php

namespace Brume\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use RuntimeException;

final class Assets
{
    /** Entry key of the precompiled frontend inside the Vite manifest. */
    public const ENTRY = 'stubs/frontend/app.tsx';

    /** Where `php artisan vendor:publish --tag=brume-assets` copies dist/ to. */
    public const PUBLISH_DIR = 'vendor/brume';

    public static string $manifestPath = __DIR__.'/../../dist/.vite/manifest.json';

    public static function tags(): HtmlString
    {
        return config('brume.frontend.mode') === 'vite'
            ? self::viteTags()
            : self::precompiledTags();
    }

    /**
     * Hash of the precompiled manifest, used as the Inertia asset version.
     */
    public static function version(): ?string
    {
        return is_file(self::$manifestPath) ? md5_file(self::$manifestPath) : null;
    }

    private static function viteTags(): HtmlString
    {
        // Clone so the project's own Vite instance keeps its hot file and build directory.
        $vite = (clone app(Vite::class))
            ->useHotFile(public_path('brume.hot'))
            ->useBuildDirectory('build-brume')
            ->withEntryPoints(['resources/js/brume/app.tsx']);

        return new HtmlString($vite->reactRefresh().$vite->toHtml());
    }

    private static function precompiledTags(): HtmlString
    {
        if (! is_file(self::$manifestPath)) {
            throw new RuntimeException('找不到 Brume 的預編譯前端資源（'.self::$manifestPath.'），請重新安裝 brume/laravel，或改用 BRUME_FRONTEND=vite。');
        }

        if (app()->environment('local')) {
            self::ensurePublished();
        }

        $manifest = json_decode(file_get_contents(self::$manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $entry = $manifest[self::ENTRY] ?? throw new RuntimeException('Brume 的前端資源清單缺少入口 '.self::ENTRY.'，請重新安裝 brume/laravel。');

        $tags = array_map(
            fn (string $css) => '<link rel="stylesheet" href="'.e(asset(self::PUBLISH_DIR.'/'.$css)).'">',
            $entry['css'] ?? [],
        );
        $tags[] = '<script type="module" src="'.e(asset(self::PUBLISH_DIR.'/'.$entry['file'])).'"></script>';

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * The published copy must exist and match the package's manifest, otherwise the browser
     * would load missing or outdated files after a composer update.
     */
    private static function ensurePublished(): void
    {
        $published = public_path(self::PUBLISH_DIR.'/.vite/manifest.json');

        if (! is_file($published) || md5_file($published) !== self::version()) {
            throw new RuntimeException('Brume 前端資源還沒發布或已過期，請執行 php artisan vendor:publish --tag=brume-assets --force');
        }
    }
}
