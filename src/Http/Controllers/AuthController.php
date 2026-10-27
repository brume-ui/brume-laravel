<?php

namespace Brume\Http\Controllers;

use Brume\Facades\Brume;
use Brume\Http\Middleware\Authenticate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class AuthController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function show(Request $request): Response
    {
        return Brume::page('brume/login', array_filter([
            'status' => $request->session()->get('status'),
        ]));
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => __('brume::auth.username_required'),
            'password.required' => __('brume::auth.password_required'),
        ]);

        // Namespaced so it never shares a counter with the host's own login limiter.
        $throttleKey = 'brume-login:'.Str::lower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'username' => __('brume::auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        if (! $this->guard()->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => __('brume::auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->to($this->intendedUrl($request) ?? route('brume.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('brume.login');
    }

    /**
     * The page the guest asked for before being sent to login. Kept under its own session key
     * (the host's url.intended is left alone) and only honoured inside the Brume prefix.
     */
    private function intendedUrl(Request $request): ?string
    {
        $url = $request->session()->pull(Authenticate::INTENDED_KEY);

        if (! is_string($url) || parse_url($url, PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }

        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $home = Brume::url('/');

        return $home === '/' || $path === $home || str_starts_with($path, $home.'/') ? $url : null;
    }

    private function guard()
    {
        return Auth::guard(config('brume.auth.guard'));
    }
}
