<?php

use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('brume-login:admin|127.0.0.1');
});

it('resets the password so the admin can sign in with the new one', function () {
    brumeUser();

    $this->artisan('brume:password', ['username' => 'admin'])
        ->expectsQuestion('新密碼（至少 8 碼）', 'brand-new-password')
        ->expectsQuestion('再輸入一次密碼', 'brand-new-password')
        ->expectsOutputToContain('admin')
        ->assertSuccessful();

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret-password'])
        ->assertSessionHasErrors('username');
    $this->assertGuest('brume');

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'brand-new-password'])
        ->assertRedirect('/admin');
    $this->assertAuthenticated('brume');
});

it('fails with an explanation when the admin does not exist', function () {
    $this->artisan('brume:password', ['username' => 'ghost'])
        ->expectsOutputToContain('找不到帳號「ghost」')
        ->assertFailed();
});
