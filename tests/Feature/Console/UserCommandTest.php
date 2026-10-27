<?php

use Brume\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an admin from options', function () {
    $this->artisan('brume:user', ['username' => 'mia', '--name' => '米亞', '--password' => 'secret-password'])
        ->expectsOutputToContain('mia')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->username)->toBe('mia')
        ->and($user->name)->toBe('米亞')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('asks for the password twice and uses the username as the default name', function () {
    $this->artisan('brume:user', ['username' => 'mia'])
        ->expectsQuestion('密碼（至少 8 碼）', 'secret-password')
        ->expectsQuestion('再輸入一次密碼', 'secret-password')
        ->assertSuccessful();

    expect(User::query()->sole()->name)->toBe('mia');
});

it('asks again when the two passwords differ', function () {
    $this->artisan('brume:user', ['username' => 'mia'])
        ->expectsQuestion('密碼（至少 8 碼）', 'secret-password')
        ->expectsQuestion('再輸入一次密碼', 'other-password')
        ->expectsOutputToContain('兩次輸入的密碼不一樣')
        ->expectsQuestion('密碼（至少 8 碼）', 'secret-password')
        ->expectsQuestion('再輸入一次密碼', 'secret-password')
        ->assertSuccessful();

    expect(Hash::check('secret-password', User::query()->sole()->password))->toBeTrue();
});

it('fails with an explanation when the username is taken', function () {
    brumeUser(['username' => 'mia']);

    $this->artisan('brume:user', ['username' => 'mia', '--password' => 'secret-password'])
        ->expectsOutputToContain('帳號「mia」已經存在')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('rejects a password shorter than 8 characters', function () {
    $this->artisan('brume:user', ['username' => 'mia', '--password' => 'short'])
        ->expectsOutputToContain('至少 8 碼')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
