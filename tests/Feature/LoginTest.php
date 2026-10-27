<?php

use Brume\Facades\Brume;
use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    RateLimiter::clear('brume-login:admin|127.0.0.1');
});

it('redirects guests from the admin home to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('renders the brume/login page with shared brume props for guests', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brume/login')
            ->where('brume.app.name', 'Brume')
            ->where('brume.app.locale', 'zh-TW')
            ->where('brume.auth.user', null)
            ->where('brume.flash', null));
});

it('logs in with the right credentials and redirects to the admin home', function () {
    brumeUser();

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password'])
        ->assertRedirect('/admin');

    $this->assertAuthenticated('brume');
});

it('rejects a wrong password with a message that says how to fix it', function () {
    brumeUser();

    $this->from('/admin/login')
        ->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
        ->assertRedirect('/admin/login')
        ->assertSessionHasErrors(['username' => '帳號或密碼不正確，請再試一次。']);

    $this->assertGuest('brume');
});

it('throttles the sixth failed attempt for the same username and ip', function () {
    brumeUser();

    foreach (range(1, 5) as $attempt) {
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertSessionHasErrors(['username' => '帳號或密碼不正確，請再試一次。']);
    }

    $response = $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong']);

    $response->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))
        ->toMatch('/^嘗試次數過多，請在 \d+ 秒後再試。$/');
});

it('sets the remember cookie when "remember me" is checked', function () {
    brumeUser();

    $response = $this->post('/admin/login', [
        'username' => 'admin',
        'password' => 'secret-password',
        'remember' => true,
    ]);

    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    expect(array_filter($names, fn ($name) => str_starts_with($name, 'remember_brume_')))->not->toBeEmpty();
});

it('does not set the remember cookie without "remember me"', function () {
    brumeUser();

    $response = $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password']);

    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    expect(array_filter($names, fn ($name) => str_starts_with($name, 'remember_brume_')))->toBeEmpty();
});

it('logs out, invalidates the session and sends the user back to login', function () {
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    session()->put('marker', 'before-logout');
    $oldSessionId = session()->getId();

    $this->post('/admin/logout')->assertRedirect('/admin/login');

    $this->assertGuest('brume');
    expect(session()->getId())->not->toBe($oldSessionId)
        ->and(session('marker'))->toBeNull();
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('sends an already logged-in user from the login page to the admin home', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->get('/admin/login')->assertRedirect('/admin');
});

it('only authenticates the brume guard, leaving the web guard a guest', function () {
    brumeUser();

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password']);

    $this->assertAuthenticated('brume');
    $this->assertGuest('web');
});

it('namespaces the throttle key so host login limiters are not affected', function () {
    brumeUser();

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong']);

    expect(RateLimiter::attempts('brume-login:admin|127.0.0.1'))->toBe(1)
        ->and(RateLimiter::attempts('admin|127.0.0.1'))->toBe(0);
});

it('returns to the brume page the guest originally asked for', function () {
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->get('reports', fn () => Brume::page('demo/reports'));
    brumeUser();

    $this->get('/admin/reports?range=week')->assertRedirect('/admin/login');

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password'])
        ->assertRedirect('/admin/reports?range=week');
});

it('does not let the host app intended url hijack the brume login redirect', function () {
    brumeUser();

    $this->withSession(['url.intended' => 'http://localhost/dashboard'])
        ->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password'])
        ->assertRedirect('/admin');

    expect(session('url.intended'))->toBe('http://localhost/dashboard');
});

it('ignores a brume intended url that points outside the admin prefix', function () {
    brumeUser();

    $this->withSession(['brume.intended' => 'http://localhost/dashboard'])
        ->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password'])
        ->assertRedirect('/admin');
});
