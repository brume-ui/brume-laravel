<?php

namespace Workbench\Database\Factories;

use Faker\Generator;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'number' => 'BR'.$this->faker->unique()->numerify('########'),
            'customer' => $this->faker->name(),
            'amount' => $this->faker->numberBetween(12, 980) * 10,
            // Mostly finished orders, a few open or cancelled ones.
            'status' => $this->faker->randomElement([
                'pending', 'paid', 'paid', 'shipped', 'shipped',
                'completed', 'completed', 'completed', 'completed', 'cancelled',
            ]),
            // Lazy, so a state that sets the category (the seeder) draws nothing here.
            'category' => fn () => $this->faker->randomElement(Order::CATEGORIES),
        ];
    }

    /**
     * Taiwanese customer names: the zh_TW Faker (the same shared instance the seeder seeds).
     */
    protected function withFaker(): Generator
    {
        return Container::getInstance()->make(Generator::class, ['locale' => 'zh_TW']);
    }
}
