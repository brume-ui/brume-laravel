<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\ProductCategoryController;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\ProductCategory;
use Workbench\Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('product-categories', ProductCategoryController::class)->names('brume.product-categories');
    });
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CatalogSeeder::class);
});

function categoryValues($test, int $id): array
{
    return $test->getJson("/admin/product-categories/{$id}/edit", ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
}

function categoryOptions($test, string $url, array $query): array
{
    return $test->getJson("{$url}/_brume/form-options?".http_build_query($query))->assertOk()->json('data');
}

it('moves selected products from another category and nulls deselected ones', function () {
    [$a, $b] = [ProductCategory::findOrFail(1), ProductCategory::findOrFail(2)];
    $moving = CatalogProduct::where('category_id', $b->id)->firstOrFail();
    $kept = $a->products()->firstOrFail();
    $dropped = $a->products()->where('id', '!=', $kept->id)->firstOrFail();
    $count = CatalogProduct::count();

    $values = categoryValues($this, $a->id);
    $values['products'] = [['id' => (string) $kept->id], ['id' => (string) $moving->id]];
    $this->putJson("/admin/product-categories/{$a->id}", ['values' => $values])->assertOk();

    expect($moving->fresh()->category_id)->toBe($a->id)
        ->and($dropped->fresh()->category_id)->toBeNull()
        ->and($kept->fresh()->category_id)->toBe($a->id)
        ->and(CatalogProduct::count())->toBe($count)
        ->and(CatalogProduct::whereKey($dropped->id)->exists())->toBeTrue()
        ->and($b->products()->whereKey($moving->id)->exists())->toBeFalse();
});

it('pages the product source and rejects out-of-scope ids', function () {
    $category = ProductCategory::findOrFail(1);
    $url = "/admin/product-categories/{$category->id}";
    $ids = fn (int $page) => array_column(categoryOptions($this, $url, ['field' => 'products', 'page' => $page]), 'value');

    $first = $ids(1);
    $second = $ids(2);
    expect($first)->not->toBeEmpty()->and($second)->not->toBeEmpty()->and($second[0])->not->toBe($first[0])
        ->and(array_intersect($first, $second))->toBe([]);

    $before = $category->products()->pluck('id')->sort()->values()->all();
    $values = categoryValues($this, $category->id);
    $values['products'] = [['id' => '999999']];
    $this->putJson($url, ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('products');
    expect($category->products()->pluck('id')->sort()->values()->all())->toBe($before);
});

it('rejects a parent that is the category itself or a descendant', function () {
    $root = ProductCategory::whereNull('parent_id')->whereHas('children')->firstOrFail();
    $child = $root->children()->firstOrFail();
    $url = "/admin/product-categories/{$root->id}";
    $options = array_column(categoryOptions($this, $url, ['field' => 'parent_id']), 'value');

    expect($options)->not->toContain((string) $root->id)->and($options)->not->toContain((string) $child->id);

    foreach ([$root->id, $child->id] as $forged) {
        $values = categoryValues($this, $root->id);
        $values['parent_id'] = (string) $forged;
        $this->putJson($url, ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }
    expect($root->fresh()->parent_id)->toBeNull();
});

it('lists the hierarchy with indented names and product counts', function () {
    $page = $this->get('/admin/product-categories')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['columns'], 'key'))->toContain('name', 'products_count')
        ->and($page['rows'])->toHaveCount(ProductCategory::count());
});
