<?php

namespace Brume\Facades;

use Brume\BrumeManager;
use Brume\Contracts\Renderable;
use Illuminate\Support\Facades\Facade;
use Inertia\Response;

/**
 * @method static Response render(Renderable $page)
 * @method static Response page(string $component, array $props = [])
 * @method static void toast(string $type, string $message)
 * @method static string url(string $path = '/')
 *
 * @see BrumeManager
 */
class Brume extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BrumeManager::class;
    }
}
