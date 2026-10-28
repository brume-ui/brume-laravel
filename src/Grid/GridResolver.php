<?php

namespace Brume\Grid;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;

/**
 * Builds the Grid behind a Route::brumeGrid page from one of its `_brume` endpoints, by calling the
 * page's own action with the current route parameters (the endpoint's own `action`, `id` and
 * `source` left out), route models bound as they are for the page.
 */
final class GridResolver
{
    /** Parameters the `_brume` endpoints add to the page's URI. */
    public const ENDPOINT_PARAMETERS = ['action', 'id', 'source'];

    /**
     * @param  string|array{0: class-string, 1: string}|Closure  $action  as given to Route::brumeGrid
     */
    public static function resolve(string|array|Closure $action, Request $request): Grid
    {
        $route = self::pageRoute($action, $request);
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            $grid = app()->call($uses, $route->parameters);
        } else {
            [$class, $method] = Str::parseCallback($uses);
            $grid = app()->call([app($class), $method], $route->parameters);
        }

        if (! $grid instanceof Grid) {
            throw new LogicException(sprintf(
                'Route::brumeGrid 的 controller 必須回傳 Grid，%s 回傳的是 %s。',
                $uses instanceof Closure ? 'closure' : $uses,
                get_debug_type($grid),
            ));
        }

        return $grid;
    }

    /**
     * A route for the page's action carrying the current parameters, so implicit model binding
     * follows the page action's signature.
     */
    protected static function pageRoute(string|array|Closure $action, Request $request): Route
    {
        $current = $request->route();
        $parameters = $current instanceof Route ? $current->parameters() : [];

        // A bare class string is what the Router would turn into Class@__invoke, so hand it over the same way.
        $route = (new Route(['GET'], $current instanceof Route ? $current->uri() : '/', is_string($action) ? ['uses' => $action] : $action))
            ->setContainer(app())
            ->setRouter(app('router'));

        $route->parameters = Arr::where(
            Arr::except($parameters, self::ENDPOINT_PARAMETERS),
            fn ($value, string $name) => ! str_starts_with($name, '_brume'),
        );

        app('router')->substituteImplicitBindings($route);

        return $route;
    }
}
