<?php

use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\Order;
use Workbench\App\Models\ProductCategory;
use Workbench\App\Models\Promotion;
use Workbench\App\Models\PromotionFreebie;
use Workbench\App\Models\ShowcaseItem;
use Workbench\Database\Seeders\CatalogSeeder;
use Workbench\Database\Seeders\DatabaseSeeder;

it('seeds a repeatable catalog with hierarchy, ERP rows, paged products and historical gift counters', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);

    expect(Order::count())->toBe(DatabaseSeeder::ORDERS)
        ->and(ShowcaseItem::count())->toBe(DatabaseSeeder::SHOWCASE_ITEMS);

    expect(CatalogProduct::count())->toBe(64)
        ->and(CatalogProduct::orderBy('id')->first()->identifier)->toBe('DEMO-001')
        ->and(CatalogProduct::orderByDesc('id')->first()->identifier)->toBe('DEMO-064')
        ->and(CatalogProduct::where('identifier', 'DEMO-001')->value('price'))->toBe('65.00')
        ->and(CatalogProduct::where('identifier', 'DEMO-010')->value('price'))->toBe('200.00')
        ->and(CatalogProduct::whereNotNull('erp_updated_at')->count())->toBe(2);

    $categories = ProductCategory::orderBy('id')->get();
    expect($categories)->toHaveCount(3)
        ->and($categories->map->tree_depth->all())->toBe([0, 0, 1])
        ->and($categories[2]->parent->is($categories[0]))->toBeTrue()
        ->and($categories[0]->descendantIds())->toBe([$categories[2]->id])
        ->and($categories[1]->descendantIds())->toBe([]);

    $promotion = Promotion::sole();
    expect($promotion->name)->toBe('滿額贈示範');

    $conditions = $promotion->conditions;
    expect($conditions->pluck('amount')->all())->toBe(['500.00', '1000.00']);

    $actions = $conditions->flatMap->actions;
    expect($actions->pluck('strategy')->unique()->sort()->values()->all())->toBe(['all', 'choose']);

    expect(PromotionFreebie::where('already_given_count', '>', 0)->exists())->toBeTrue()
        ->and($actions->flatMap->freebies->count())->toBeGreaterThanOrEqual(3);
});
