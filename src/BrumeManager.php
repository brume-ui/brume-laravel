<?php

namespace Brume;

use Brume\Contracts\Renderable;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BrumeManager
{
    /** Version of the @brume-ui/brume-schema contract this package emits. */
    public const SCHEMA_VERSION = '0.3.0';

    public const TOAST_TYPES = ['success', 'error', 'info', 'warning'];

    /**
     * Render a schema-driven page through the generic `brume/page` component.
     */
    public function render(Renderable $page): Response
    {
        return Inertia::render('brume/page', [
            'envelope' => [
                'schemaVersion' => self::SCHEMA_VERSION,
                'page' => $page->toSchema(request()),
            ],
        ]);
    }

    /**
     * Render a hand-written React page.
     */
    public function page(string $component, array $props = []): Response
    {
        return Inertia::render($component, $props);
    }

    /**
     * Show a toast on the next page; shared as `brume.flash` with a fresh id per response.
     */
    public function toast(string $type, string $message): void
    {
        if (! in_array($type, self::TOAST_TYPES, true)) {
            throw new InvalidArgumentException("Toast 類型「{$type}」不存在，請改用 ".implode('、', self::TOAST_TYPES).' 其中之一。');
        }

        session()->flash('brume.flash', ['type' => $type, 'message' => $message]);
    }

    /**
     * Prefix a path with the Brume route prefix: url('/') is /admin, url('/users') is /admin/users.
     */
    public function url(string $path = '/'): string
    {
        $prefix = trim((string) config('brume.route.prefix'), '/');

        return '/'.trim($prefix.'/'.ltrim($path, '/'), '/');
    }
}
