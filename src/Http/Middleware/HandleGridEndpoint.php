<?php

namespace Brume\Http\Middleware;

use Brume\Grid\GridRouteRegistrar;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the page action on a `_brume` endpoint route. It moves the endpoint's own parameter
 * (action key, row id, options source) off the route, so the page action receives exactly the
 * page's parameters, and checks that the action returned a Grid.
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
        $parameter = GridRouteRegistrar::ENDPOINT_PARAMETERS[$route->getAction(GridRouteRegistrar::ACTION_KEY)] ?? null;

        if ($parameter !== null) {
            $request->attributes->set(self::ARGUMENT, $route->parameter($parameter));
            $route->forgetParameter($parameter);
        }

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
}
