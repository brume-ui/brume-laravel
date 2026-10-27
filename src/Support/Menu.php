<?php

namespace Brume\Support;

use Brume\Facades\Brume;

/**
 * Turns config('brume.menu') into the MenuItem[] the frontend expects (prefixed urls,
 * translated titles) and derives breadcrumbs for the current path.
 */
final class Menu
{
    /** @var list<array{title: string, icon?: string, url?: string, children?: list<array<string, mixed>>}> */
    private array $items;

    /**
     * @param  list<array<string, mixed>>  $config
     */
    public function __construct(array $config)
    {
        $this->items = array_map(self::normalize(...), $config);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Breadcrumbs for $path (e.g. /admin/users/5/edit): the home item followed by the menu
     * path to the item whose url matches exactly, else the longest url prefix (home excluded).
     * Empty on the home page itself and on pages the menu does not lead to.
     *
     * @return list<array{title: string, url?: string}>
     */
    public function breadcrumbs(string $path): array
    {
        $home = Brume::url('/');
        $path = '/'.trim($path, '/');

        if ($path === $home) {
            return [];
        }

        $trail = $this->find($this->items, $path, exact: true)
            ?? $this->find($this->items, $path, exact: false);

        if ($trail === null) {
            return [];
        }

        $homeItem = $this->find($this->items, $home, exact: true);
        $homeCrumb = $homeItem ? end($homeItem) : ['title' => __('brume::menu.home'), 'url' => $home];

        return array_map(
            fn (array $item) => array_filter(
                ['title' => $item['title'], 'url' => $item['url'] ?? null],
                fn ($value) => $value !== null,
            ),
            [$homeCrumb, ...$trail],
        );
    }

    /**
     * @return list<array<string, mixed>>|null the chain of items from the root to the match
     */
    private function find(array $items, string $path, bool $exact): ?array
    {
        $best = null;

        foreach ($items as $item) {
            $url = $item['url'] ?? null;

            if ($url !== null && $url === $path) {
                return [$item];
            }

            if (! $exact && $url !== null && $url !== Brume::url('/') && str_starts_with($path, rtrim($url, '/').'/')) {
                $best = $this->longer($best, [$item]);
            }

            if ($childTrail = $this->find($item['children'] ?? [], $path, $exact)) {
                if ($exact) {
                    return [$item, ...$childTrail];
                }

                $best = $this->longer($best, [$item, ...$childTrail]);
            }
        }

        return $best;
    }

    private function longer(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }

        return strlen(end($candidate)['url'] ?? '') > strlen(end($current)['url'] ?? '') ? $candidate : $current;
    }

    private static function normalize(array $item): array
    {
        $title = __($item['title']);

        $normalized = ['title' => is_string($title) ? $title : $item['title']];

        if (isset($item['icon'])) {
            $normalized['icon'] = $item['icon'];
        }

        if (isset($item['url'])) {
            $normalized['url'] = self::url($item['url']);
        }

        if (! empty($item['children'])) {
            $normalized['children'] = array_map(self::normalize(...), $item['children']);
        }

        return $normalized;
    }

    private static function url(string $url): string
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $url) ? $url : Brume::url($url);
    }
}
