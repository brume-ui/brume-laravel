<?php

namespace Workbench\Database\Seeders;

use Brume\Models\User;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\Order;

/**
 * Demo data for the workbench and the e2e suite. Faker is seeded, so every run produces the
 * same admins and orders in the same order (stable assertions and screenshots).
 */
class DatabaseSeeder extends Seeder
{
    public const FAKE_ADMINS = 35;

    public const ORDERS = 60;

    /** The newest order; the dashboard reports the months up to this one. */
    public const LAST_ORDER_AT = '2026-10-27 18:00:00';

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

        $this->seedOrders();
    }

    /**
     * One order every three days for the ~6 months before LAST_ORDER_AT, oldest first.
     */
    private function seedOrders(): void
    {
        // OrderFactory draws from this shared zh_TW Faker instance; seed it for stable data.
        app(Generator::class, ['locale' => 'zh_TW'])->seed(20261028);

        $last = CarbonImmutable::parse(self::LAST_ORDER_AT);

        Order::factory()
            ->count(self::ORDERS)
            ->state(new Sequence(function (Sequence $sequence) use ($last) {
                $createdAt = $last
                    ->subDays((self::ORDERS - 1 - $sequence->index) * 3)
                    ->subMinutes(($sequence->index * 97) % 480);

                return [
                    'number' => sprintf('BR-%s-%03d', $createdAt->format('Ymd'), $sequence->index + 1),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }))
            ->create();
    }
}
