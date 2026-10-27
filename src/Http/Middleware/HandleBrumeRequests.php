<?php

namespace Brume\Http\Middleware;

use Brume\BrumeManager;
use Brume\Facades\Brume;
use Brume\Support\Assets;
use Brume\Support\Color;
use Brume\Support\LocaleMap;
use Brume\Support\Menu;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleBrumeRequests extends Middleware
{
    /** Cookie the frontend locale switcher writes (zh-TW | en | zh-CN); excluded from encryption. */
    public const LOCALE_COOKIE = 'brume_locale';

    protected $rootView = 'brume::app';

    public function handle(Request $request, Closure $next)
    {
        // Unencrypted, so a client can send anything, including brume_locale[x]=y (an array).
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        app()->setLocale(LocaleMap::resolve(is_string($cookie) ? $cookie : null));

        return parent::handle($request, $next);
    }

    public function version(Request $request): ?string
    {
        return Assets::version();
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Resolved lazily, once per response: after the controller ran (login, toast, ...).
            'brume' => fn () => $this->sharedProps($request),
        ];
    }

    /**
     * The SharedProps shape of @brume-ui/brume-schema.
     *
     * @return array<string, mixed>
     */
    protected function sharedProps(Request $request): array
    {
        $menu = new Menu(config('brume.menu', []));

        return [
            'schemaVersion' => BrumeManager::SCHEMA_VERSION,
            'app' => [
                'name' => config('brume.name'),
                'locale' => LocaleMap::toFrontend(app()->getLocale()),
                'locales' => array_map(LocaleMap::toFrontend(...), config('brume.locales')),
                'homeUrl' => Brume::url('/'),
                'logoutUrl' => route('brume.logout', absolute: false),
                'layout' => [
                    'menuPosition' => config('brume.layout.menu_position'),
                    'accent' => Color::accent(),
                    'sidebarCollapsible' => (bool) config('brume.layout.sidebar_collapsible'),
                    'breadcrumbs' => (bool) config('brume.layout.breadcrumbs'),
                ],
            ],
            'auth' => [
                'user' => $this->user(),
            ],
            'menu' => $menu->items(),
            'breadcrumbs' => $menu->breadcrumbs($request->path()),
            // A new id every time, so the same message flashed twice still shows a second toast.
            'flash' => fn () => ($flash = $request->session()->get('brume.flash'))
                ? [...$flash, 'id' => (string) Str::uuid()]
                : null,
        ];
    }

    /**
     * Only what the UI needs; never the password hash or remember token.
     *
     * @return array{id: int|string, name: string, username: string, avatar: string|null}|null
     */
    protected function user(): ?array
    {
        $user = Auth::guard(config('brume.auth.guard'))->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'name' => (string) $user->name,
            'username' => (string) $user->username,
            'avatar' => $user->avatar,
        ];
    }
}
