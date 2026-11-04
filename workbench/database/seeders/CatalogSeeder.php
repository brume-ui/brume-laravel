<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\ProductCategory;
use Workbench\App\Models\Promotion;
use Workbench\App\Models\PromotionAction;
use Workbench\App\Models\PromotionCondition;
use Workbench\App\Models\PromotionFreebie;

/**
 * Deterministic 商品 / 商品分類 / 滿額贈 demo data. Everything is keyed by identifier or fixed id,
 * so running it again leaves the same rows (the admin and showcase seeding stays in DatabaseSeeder).
 */
class CatalogSeeder extends Seeder
{
    public const PRODUCTS = 64;

    public function run(): void
    {
        $this->seedCategories();
        $this->seedProducts();
        $this->seedPromotion();
    }

    private function seedCategories(): void
    {
        foreach ([
            [1, null, '服飾', 1],
            [2, null, '家居', 2],
            [3, 1, '外套', 1],
        ] as [$id, $parentId, $name, $position]) {
            ProductCategory::query()->updateOrCreate(['id' => $id], ['parent_id' => $parentId, 'name' => $name, 'position' => $position]);
        }
    }

    private function seedProducts(): void
    {
        $at = '2026-10-27 12:00:00';

        for ($i = 1; $i <= self::PRODUCTS; $i++) {
            $isBundle = $i === self::PRODUCTS;

            CatalogProduct::query()->updateOrCreate(['identifier' => sprintf('DEMO-%03d', $i)], [
                'name' => sprintf('示範商品 %03d', $i),
                'barcode' => sprintf('471000%07d', $i),
                'is_published' => $i % 4 !== 0,
                'price' => 50 + $i * 15,
                'stock' => 100 - $i,
                'is_preorder' => $i % 10 === 0,
                'preorder_ships_at' => $i % 10 === 0 ? '2026-12-01 09:00:00' : null,
                'category_id' => $i % 4 === 0 ? null : [1, 2, 3][$i % 3],
                'is_bundle' => $isBundle,
                'per_order_limit' => $i % 7 === 0 ? 3 : null,
                // The two ERP rows carry the protected counters and sync stamp.
                'total_limit' => in_array($i, [3, 5], true) ? 100 : null,
                'remaining_limit' => in_array($i, [3, 5], true) ? 100 - $i : null,
                'sold_count' => in_array($i, [3, 5], true) ? $i : 0,
                'erp_updated_at' => in_array($i, [3, 5], true) ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $bundle = CatalogProduct::query()->where('identifier', sprintf('DEMO-%03d', self::PRODUCTS))->firstOrFail();
        $bundle->components()->sync([
            CatalogProduct::query()->where('identifier', 'DEMO-001')->value('id') => ['quantity' => 2, 'position' => 1],
            CatalogProduct::query()->where('identifier', 'DEMO-002')->value('id') => ['quantity' => 1, 'position' => 2],
        ]);
    }

    private function seedPromotion(): void
    {
        $product = fn (int $n): int => (int) CatalogProduct::query()->where('identifier', sprintf('DEMO-%03d', $n))->value('id');

        Promotion::query()->updateOrCreate(['id' => 1], [
            'name' => '滿額贈示範',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-30 23:59:59',
            'is_enabled' => true,
        ]);

        foreach ([[1, '500.00', 1], [2, '1000.00', 2]] as [$id, $amount, $position]) {
            PromotionCondition::query()->updateOrCreate(['id' => $id], ['promotion_id' => 1, 'amount' => $amount, 'position' => $position]);
        }

        PromotionAction::query()->withTrashed()->updateOrCreate(['id' => 1], ['condition_id' => 1, 'strategy' => 'all', 'choose_count' => null, 'position' => 1, 'deleted_at' => null]);
        PromotionAction::query()->withTrashed()->updateOrCreate(['id' => 2], ['condition_id' => 2, 'strategy' => 'choose', 'choose_count' => 1, 'position' => 1, 'deleted_at' => null]);

        foreach ([
            [1, 1, $product(10), false, null, 0, 0],
            [2, 2, $product(11), true, 50, 12, 30],
            [3, 2, $product(12), true, 20, 0, 8],
        ] as $position => [$id, $actionId, $productId, $limited, $limit, $given, $already]) {
            PromotionFreebie::query()->withTrashed()->updateOrCreate(['id' => $id], [
                'action_id' => $actionId,
                'product_id' => $productId,
                'is_limited' => $limited,
                'given_limit' => $limit,
                'given_count' => $given,
                'already_given_count' => $already,
                'position' => $position + 1,
                'deleted_at' => null,
            ]);
        }
    }
}
