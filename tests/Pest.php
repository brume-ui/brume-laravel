<?php

use Brume\Models\User;
use Brume\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)->in('Feature');
uses(RefreshDatabase::class)->in('Feature');

function brumeUser(array $attributes = []): User
{
    return User::query()->create([
        'username' => 'admin',
        'password' => 'secret-password',
        'name' => '管理員',
        ...$attributes,
    ]);
}
