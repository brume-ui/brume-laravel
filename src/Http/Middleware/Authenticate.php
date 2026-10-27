<?php

namespace Brume\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a user on the brume guard (not the project's default guard).
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('brume.auth.guard');

        if (! Auth::guard($guard)->check()) {
            return redirect()->guest(route('brume.login'));
        }

        Auth::shouldUse($guard);

        return $next($request);
    }
}
