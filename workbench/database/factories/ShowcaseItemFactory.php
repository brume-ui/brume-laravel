<?php

namespace Workbench\Database\Factories;

use Faker\Generator;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\ShowcaseItem;

/**
 * @extends Factory<ShowcaseItem>
 */
class ShowcaseItemFactory extends Factory
{
    protected $model = ShowcaseItem::class;

    private const NAMES = ['霧面馬克杯', '亞麻餐墊', '木柄湯匙', '帆布托特包', '玻璃水瓶', '陶瓷小碟', '筆記本', '黃銅書籤', '棉麻抱枕', '桌燈', '香氛蠟燭', '竹製砧板'];

    public function definition(): array
    {
        $color = $this->faker->randomElement(array_keys(ShowcaseItem::COLORS));
        $swatch = fn (string $color) => '/admin/_demo/swatch/'.$color.'.svg';

        return [
            'name' => $this->faker->randomElement(self::NAMES),
            'sku' => 'SKU-'.$this->faker->unique()->numerify('#####'),
            'slogan' => $this->faker->randomElement(['每天都想用', '安靜的好設計', '放在桌上剛剛好', '送禮自用兩相宜']),
            'note' => $this->faker->optional(0.7)->randomElement(['週末補貨', '包裝要加厚', '樣品在二樓', '等廠商回覆']),
            'description' => $this->faker->randomElement([
                '手工製作，每一件的紋路都略有不同，適合日常使用也適合送禮。',
                '耐熱、可微波，洗碗機可洗；表面霧面處理，不容易留下指紋。',
                '天然材質，第一次使用前請先用清水沖洗並自然風乾。',
            ]),
            'category' => $this->faker->randomElement(array_keys(ShowcaseItem::CATEGORIES)),
            'status' => $this->faker->randomElement(array_keys(ShowcaseItem::STATUSES)),
            'warehouse' => $this->faker->randomElement(array_keys(ShowcaseItem::WAREHOUSES)),
            'price' => $this->faker->numberBetween(8, 260) * 10,
            'stock' => $this->faker->numberBetween(0, 120),
            'progress' => $this->faker->numberBetween(0, 20) * 5,
            'rating' => $this->faker->numberBetween(1, 5),
            'views' => $this->faker->numberBetween(0, 5000),
            'tags' => implode(',', $this->faker->randomElements(array_keys(ShowcaseItem::TAGS), $this->faker->numberBetween(1, 3))),
            'channels' => $this->faker->randomElements(array_keys(ShowcaseItem::CHANNELS), $this->faker->numberBetween(1, 3)),
            'meta' => ['color' => $color],
            'images' => array_map($swatch, array_values(array_unique([$color, $this->faker->randomElement(array_keys(ShowcaseItem::COLORS))]))),
            'attachments' => [['url' => $swatch($color).'?download=1', 'name' => '色票.svg']],
            'variants' => [
                ['size' => 'S', 'stock' => $this->faker->numberBetween(0, 30)],
                ['size' => 'M', 'stock' => $this->faker->numberBetween(0, 30)],
            ],
            'is_active' => $this->faker->boolean(80),
            'is_featured' => $this->faker->boolean(30),
            'is_new' => $this->faker->boolean(40),
            'released_at' => $this->faker->dateTimeBetween('2026-01-01', '2026-10-27')->format('Y-m-d'),
        ];
    }

    /**
     * The shared zh_TW Faker, as OrderFactory uses (the seeder seeds it).
     */
    protected function withFaker(): Generator
    {
        return Container::getInstance()->make(Generator::class, ['locale' => 'zh_TW']);
    }
}
