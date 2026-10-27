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
    /** Session key for the page to return to after login; separate from the host's url.intended. */
    public const INTENDED_KEY = 'brume.intended';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('brume.auth.guard');

        if (! Auth::guard($guard)->check()) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
            }

            return redirect()->route('brume.login');
        }

        Auth::shouldUse($guard);

        return $next($request);
    }
}
