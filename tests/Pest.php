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

/** JSON object property order is driver-defined (MySQL reorders keys); list order is kept. */
function jsonKeysSorted(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (! is_array($value)) {
        return $value;
    }
    $value = array_map(jsonKeysSorted(...), $value);
    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}
