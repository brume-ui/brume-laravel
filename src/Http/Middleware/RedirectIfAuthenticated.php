<?php

namespace Brume\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard(config('brume.auth.guard'))->check()) {
            return redirect()->route('brume.home');
        }

        return $next($request);
    }
}
