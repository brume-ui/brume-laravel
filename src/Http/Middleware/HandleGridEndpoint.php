<?php

namespace Brume\Http\Middleware;

use Brume\Grid\GridRouteRegistrar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the page action on a `_brume` endpoint route and checks that the action returned a Grid.
 *
 * The endpoint's own parameter (action key, row id, options source) must be off the route before
 * the page action runs, so it receives exactly the page's parameters. takeArgument() does that as
 * soon as the route matches (a RouteMatched listener, so it also happens when a test disables all
 * middleware with withoutMiddleware()); this middleware and GridEndpointController call it again
 * in case that event was faked. It is idempotent.
 */
class HandleGridEndpoint
{
    /** Request attribute holding the endpoint's parameter value. */
    public const ARGUMENT = 'brume_grid.argument';

    /** Request attribute Grid::toResponse() sets once it answered the endpoint. */
    public const HANDLED = 'brume_grid.handled';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        self::takeArgument($route, $request);

        $response = $next($request);

        // A Grid answers the endpoint itself. Anything else that succeeded means the action returned
        // something other than a Grid (a redirect or an error from controller middleware passes through).
        if (! $request->attributes->get(self::HANDLED) && $response->isSuccessful()) {
            throw new LogicException(sprintf(
                'Route::brumeGrid 的 controller 必須回傳 Grid，%s 回傳的不是 Grid。',
                $route->getActionName(),
            ));
        }

        return $response;
    }

    /**
     * Moves an endpoint route's own parameter off the route into the ARGUMENT request attribute.
     * Does nothing on other routes, or when the parameter was already taken.
     */
    public static function takeArgument(Route $route, Request $request): void
    {
        $parameter = GridRouteRegistrar::ENDPOINT_PARAMETERS[$route->getAction(GridRouteRegistrar::ACTION_KEY)] ?? null;

        if ($parameter === null || ! $route->hasParameter($parameter)) {
            return;
        }

        $request->attributes->set(self::ARGUMENT, $route->parameter($parameter));
        $route->forgetParameter($parameter);
    }
}
