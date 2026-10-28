<?php

namespace Brume\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                return new JsonResponse(['message' => __('brume::auth.unauthenticated')], 401);
            }

            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
            }

            return redirect()->route('brume.login');
        }

        Auth::shouldUse($guard);

        return $next($request);
    }
}
