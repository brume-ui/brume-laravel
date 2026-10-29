<?php

namespace Brume\Http\Middleware;

use Brume\Grid\GridRouteRegistrar;
use Brume\Http\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a user on the brume guard (not the project's default guard). Pages redirect to the
 * login; JSON requests get a 401.
 */
class Authenticate
{
    /** Session key for the page to return to after login; separate from the host's url.intended. */
    public const INTENDED_KEY = 'brume.intended';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('brume.auth.guard');

        if (! Auth::guard($guard)->check()) {
            // A JSON call (the grid's `_brume` endpoints) gets a 401 ErrorResponse, not a login page.
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return ErrorResponse::make(__('brume::auth.unauthenticated'), 401);
            }

            // Not a `_brume` endpoint (an expired session clicking 匯出): after login the user should
            // land on the list, not on a download.
            if ($request->isMethod('GET') && ! $request->expectsJson() && ! $this->isGridEndpoint($request)) {
                $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
            }

            return redirect()->route('brume.login');
        }

        Auth::shouldUse($guard);

        return $next($request);
    }

    protected function isGridEndpoint(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route
            && ! in_array($route->getAction(GridRouteRegistrar::ACTION_KEY), [null, 'page'], true);
    }
}
