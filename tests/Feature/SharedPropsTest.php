<?php

use Brume\Facades\Brume;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

function sharedBrumeProps($response): array
{
    return $response->viewData('page')['props']['brume'];
}

it('shares brume props that match #/$defs/SharedProps of the v0 schema', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->get('/admin')->assertOk();
    $props = $response->viewData('page')['props'];

    expect(JsonSchema::errors(['brume' => $props['brume']], 'SharedProps'))->toBe([])
        ->and(JsonSchema::errors($props['envelope'], 'PageEnvelope'))->toBe([]);
});

it('shares app settings mapped to the frontend names', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('brume/page')
        ->where('brume.schemaVersion', '0.1.0')
        ->where('brume.app.locale', 'zh-TW')
        ->where('brume.app.locales', ['zh-TW', 'en', 'zh-CN'])
        ->where('brume.app.homeUrl', '/admin')
        ->where('brume.app.logoutUrl', '/admin/logout')
        ->where('brume.app.layout', [
            'menuPosition' => 'side',
            'accent' => '#0e7490',
            'sidebarCollapsible' => true,
            'breadcrumbs' => true,
        ]));
});

it('exposes only id, name, username and avatar of the logged-in user', function () {
    $user = brumeUser();
    $this->actingAs($user, 'brume');

    $props = sharedBrumeProps($this->get('/admin'));

    expect($props['auth']['user'])->toBe([
        'id' => $user->id,
        'name' => '管理員',
        'username' => 'admin',
        'avatar' => null,
    ]);
});

it('prefixes menu urls and translates menu titles', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('brume.menu.0.title', '儀表板')
        ->where('brume.menu.0.url', '/admin')
        ->where('brume.menu.0.icon', 'layout-dashboard'));
});

it('builds breadcrumbs from the menu path, empty on the home page and off-menu pages', function () {
    config(['brume.menu' => [
        ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
        ['title' => '使用者', 'icon' => 'users', 'children' => [
            ['title' => '使用者列表', 'url' => '/users'],
        ]],
    ]]);
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->get('users/{id}/edit', fn () => Brume::page('demo/edit'));
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->get('reports', fn () => Brume::page('demo/reports'));
    $this->actingAs(brumeUser(), 'brume');

    expect(sharedBrumeProps($this->get('/admin'))['breadcrumbs'])->toBe([]);

    expect(sharedBrumeProps($this->get('/admin/users/5/edit'))['breadcrumbs'])->toBe([
        ['title' => '儀表板', 'url' => '/admin'],
        ['title' => '使用者'],
        ['title' => '使用者列表', 'url' => '/admin/users'],
    ]);

    expect(sharedBrumeProps($this->get('/admin/reports'))['breadcrumbs'])->toBe([]);
});

it('uses the locale from the brume_locale cookie', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->withUnencryptedCookie('brume_locale', 'en')->get('/admin');

    expect(sharedBrumeProps($response)['app']['locale'])->toBe('en')
        ->and(sharedBrumeProps($response)['menu'][0]['title'])->toBe('Dashboard')
        ->and($response->getContent())->toContain('<html lang="en"');
});

it('falls back to the default locale for an unknown brume_locale cookie', function () {
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->withUnencryptedCookie('brume_locale', 'fr')->get('/admin');

    expect(sharedBrumeProps($response)['app']['locale'])->toBe('zh-TW');
});

it('ignores a locale the project did not enable', function () {
    config(['brume.locales' => ['zh_TW', 'en']]);
    $this->actingAs(brumeUser(), 'brume');

    $response = $this->withUnencryptedCookie('brume_locale', 'zh-CN')->get('/admin');

    expect(sharedBrumeProps($response)['app']['locale'])->toBe('zh-TW')
        ->and(sharedBrumeProps($response)['app']['locales'])->toBe(['zh-TW', 'en']);
});

it('uses the brume asset manifest hash as the inertia version', function () {
    $this->actingAs(brumeUser(), 'brume');

    $version = md5_file(__DIR__.'/../fixtures/manifest.json');

    expect($this->get('/admin')->viewData('page')['version'])->toBe($version);

    $this->get('/admin', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale'])
        ->assertStatus(409);
});

it('renders the root view with the color-scheme script first in head and the accent on html', function () {
    $html = $this->get('/admin/login')->getContent();

    expect($html)->toContain('style="--br-accent-custom: #0e7490"')
        ->and($html)->toMatch('/<head>\s*<script>[^<]*brume\.color-scheme/')
        ->and($html)->toContain('data-page=');
});

it('escapes the accent in the root view', function () {
    config(['brume.layout.accent' => '#0e7490"><script>x</script>']);

    expect($this->get('/admin/login')->getContent())
        ->not->toContain('"><script>x</script>');
});
