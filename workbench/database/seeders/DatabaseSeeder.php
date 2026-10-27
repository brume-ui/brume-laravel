<?php

namespace Workbench\Database\Seeders;

use Brume\Models\User;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for the workbench and the e2e suite. Faker is seeded, so every run produces the
 * same admins in the same order (stable assertions and screenshots).
 */
class DatabaseSeeder extends Seeder
{
    public const FAKE_ADMINS = 35;

    public function run(): void
    {
        $faker = Faker::create('zh_TW');
        $faker->seed(20261027);

        $start = CarbonImmutable::parse('2026-01-05 09:00:00');

        User::query()->forceCreate([
            'username' => 'admin',
            'password' => 'admin1234',
            'name' => '系統管理員',
            'created_at' => $start,
            'updated_at' => $start,
        ]);

        // One hash for every fake admin keeps seeding fast; nobody logs in as them.
        $password = Hash::make('password');

        for ($i = 1; $i <= self::FAKE_ADMINS; $i++) {
            $createdAt = $start->addDays($i * 7)->addMinutes($faker->numberBetween(0, 8 * 60));

            User::query()->forceCreate([
                'username' => sprintf('%s%02d', $faker->unique()->userName(), $i),
                'password' => $password,
                'name' => $faker->name(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
