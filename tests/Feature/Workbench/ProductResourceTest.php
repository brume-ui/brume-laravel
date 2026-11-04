<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\ProductController;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\PromotionFreebie;
use Workbench\Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('products', ProductController::class)->names('brume.products');
    });
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CatalogSeeder::class);
});

function productValues($test, string $url): array
{
    return $test->getJson($url, ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
}

it('creates and edits a product with grouped fields, images, preorder rules and components', function () {
    $form = $this->getJson('/admin/products/create', ['X-Brume-Form' => '1'])->assertOk();
    expect(array_column($form->json('page.form.nodes'), 'title'))->toBe(['基本資料', '圖片', '預購', '銷售與限購', '組合內容']);
    $values = array_replace($form->json('page.values'), ['identifier' => 'NEW-001', 'name' => '新商品', 'price' => '120', 'stock' => 5, 'is_preorder' => true]);

    $this->postJson('/admin/products', ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('preorder_ships_at');
    expect(CatalogProduct::where('identifier', 'NEW-001')->exists())->toBeFalse();

    $off = array_replace($values, ['is_preorder' => false]);
    $this->postJson('/admin/products', ['values' => $off])->assertOk();
    expect(CatalogProduct::where('identifier', 'NEW-001')->firstOrFail()->is_preorder)->toBeFalse();

    [$b, $c] = [CatalogProduct::where('identifier', 'DEMO-001')->value('id'), CatalogProduct::where('identifier', 'DEMO-002')->value('id')];
    $bundle = array_replace($values, ['identifier' => 'NEW-002', 'is_preorder' => false, 'is_bundle' => true, 'components' => [['id' => (string) $b, 'quantity' => 0]]]);
    $this->postJson('/admin/products', ['values' => $bundle])->assertUnprocessable()->assertJsonValidationErrors('components.0.quantity');

    $bundle['components'] = [['id' => (string) $b, 'quantity' => 2], ['id' => (string) $c, 'quantity' => 1]];
    $id = $this->postJson('/admin/products', ['values' => $bundle])->assertOk()->json('id');
    $rows = DB::table('catalog_product_components')->where('bundle_id', $id)->orderBy('position')->get(['component_id', 'quantity', 'position']);
    expect($rows->map(fn ($r) => [(int) $r->component_id, (int) $r->quantity, (int) $r->position])->all())->toBe([[$b, 2, 0], [$c, 1, 1]]);

    $edit = productValues($this, "/admin/products/{$id}/edit");
    $edit['name'] = '改名後';
    $this->putJson("/admin/products/{$id}", ['values' => $edit])->assertOk();
    expect(CatalogProduct::findOrFail($id)->name)->toBe('改名後');
});

it('keeps ERP-locked fields and server counters even when forged', function () {
    $erp = CatalogProduct::where('identifier', 'DEMO-003')->firstOrFail();
    $before = $erp->only(['name', 'identifier', 'price', 'stock', 'sold_count', 'remaining_limit']);
    $values = productValues($this, "/admin/products/{$erp->id}/edit");
    $values = array_replace($values, ['name' => 'forged', 'identifier' => 'FORGED', 'price' => '1', 'stock' => 9999, 'sold_count' => 0, 'remaining_limit' => 999, 'erp_updated_at' => null, 'description' => '可編輯']);
    $this->putJson("/admin/products/{$erp->id}", ['values' => $values])->assertOk();
    $fresh = $erp->fresh();
    expect($fresh->only(['name', 'identifier', 'price', 'stock', 'sold_count', 'remaining_limit']))->toBe($before)
        ->and($fresh->erp_updated_at)->not->toBeNull()
        ->and($fresh->description)->toBe('可編輯');
});

it('derives remaining limit from the stored sold count', function () {
    $product = CatalogProduct::where('identifier', 'DEMO-010')->firstOrFail();
    $product->forceFill(['sold_count' => 7, 'total_limit' => 20, 'remaining_limit' => 13, 'is_preorder' => false, 'preorder_ships_at' => null])->save();
    $url = "/admin/products/{$product->id}";
    $values = productValues($this, "{$url}/edit");

    $this->putJson($url, ['values' => array_replace($values, ['total_limit' => 10])])->assertOk();
    expect($product->fresh()->only(['total_limit', 'remaining_limit', 'sold_count']))->toBe(['total_limit' => 10, 'remaining_limit' => 3, 'sold_count' => 7]);

    $this->putJson($url, ['values' => array_replace($values, ['total_limit' => 6, 'description' => 'changed'])])
        ->assertUnprocessable()->assertJsonValidationErrors('total_limit');
    $this->putJson($url, ['values' => array_replace($values, ['total_limit' => 6, 'sold_count' => 0, 'remaining_limit' => 99])])
        ->assertUnprocessable()->assertJsonValidationErrors('total_limit');
    expect($product->fresh()->only(['total_limit', 'remaining_limit', 'sold_count', 'description']))->toBe(['total_limit' => 10, 'remaining_limit' => 3, 'sold_count' => 7, 'description' => $product->description]);

    $this->putJson($url, ['values' => array_replace($values, ['total_limit' => null])])->assertOk();
    expect($product->fresh()->only(['total_limit', 'remaining_limit', 'sold_count']))->toBe(['total_limit' => null, 'remaining_limit' => null, 'sold_count' => 7]);
});

it('rejects a bundle containing itself in search and on save', function () {
    $bundle = CatalogProduct::where('is_bundle', true)->firstOrFail();
    $search = fn (int $id, string $term) => array_column($this->getJson("/admin/products/{$id}/_brume/form-options?".http_build_query(['field' => 'components', 'q' => $term]))->assertOk()->json('data'), 'value');
    // The bundle is findable from another record's picker, but never from its own.
    $other = CatalogProduct::where('identifier', 'DEMO-001')->value('id');
    expect($search($other, $bundle->identifier))->toContain((string) $bundle->id)
        ->and($search($bundle->id, $bundle->identifier))->not->toContain((string) $bundle->id)
        ->and($search($bundle->id, '示範商品 001'))->not->toBeEmpty();

    $values = productValues($this, "/admin/products/{$bundle->id}/edit");
    $values['components'][] = ['id' => (string) $bundle->id, 'quantity' => 1];
    $before = DB::table('catalog_product_components')->where('bundle_id', $bundle->id)->count();
    $this->putJson("/admin/products/{$bundle->id}", ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('components');
    expect(DB::table('catalog_product_components')->where('bundle_id', $bundle->id)->count())->toBe($before);
});

it('lists, toggles, batch publishes and exports products', function () {
    $page = $this->get('/admin/products')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['columns'], 'key'))->toContain('identifier', 'name', 'is_published', 'price', 'stock', 'is_bundle', 'barcode', 'updated_at')
        ->and(collect($page['actions']['batch'])->pluck('key')->all())->toContain('publish', 'unpublish')
        ->and($page['rows'])->not->toBeEmpty();
    $hidden = collect($page['columns'])->where('hiddenByDefault', true)->pluck('key')->all();
    expect(array_column($page['filters']['items'], 'key'))->toBe(['is_published', 'category_id', 'is_bundle'])
        ->and($hidden)->toBe(['barcode', 'updated_at']);

    $product = CatalogProduct::where('identifier', 'DEMO-001')->firstOrFail();
    $was = $product->is_published;
    $this->patchJson("/admin/products/_brume/cells/{$product->id}", ['column' => 'is_published', 'value' => ! $was])->assertOk();
    expect($product->fresh()->is_published)->toBe(! $was);

    $a = CatalogProduct::where('identifier', 'DEMO-004')->firstOrFail();
    $b = CatalogProduct::where('identifier', 'DEMO-008')->firstOrFail();
    expect([$a->is_published, $b->is_published])->toBe([false, false]);
    $this->postJson('/admin/products/_brume/actions/publish', ['target' => 'batch', 'ids' => [$a->id, $b->id]])->assertOk();
    expect([$a->fresh()->is_published, $b->fresh()->is_published])->toBe([true, true]);

    $export = $this->get('/admin/products/_brume/export')->assertOk();
    expect($export->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($export->headers->get('content-disposition'))->toContain('products')->toContain('.xlsx');
});

it('saves a seeded preorder product back unchanged', function () {
    $product = CatalogProduct::where('identifier', 'DEMO-020')->firstOrFail();
    expect($product->is_preorder)->toBeTrue()->and($product->preorder_note)->not->toBeEmpty();
    $values = productValues($this, "/admin/products/{$product->id}/edit");
    $this->putJson("/admin/products/{$product->id}", ['values' => $values])->assertOk();
    expect($product->fresh()->preorder_note)->toBe($product->preorder_note);
});

function deleteProducts($test, array $ids)
{
    return $test->postJson('/admin/products/_brume/actions/delete', ['target' => count($ids) > 1 ? 'batch' : 'row', 'ids' => $ids])->assertOk();
}

it('refuses to delete a bundle component or a live gift, and deletes a bundle with its component rows', function () {
    $id = fn (int $n) => (int) CatalogProduct::where('identifier', sprintf('DEMO-%03d', $n))->value('id');
    $count = CatalogProduct::count();

    deleteProducts($this, [$id(1)])->assertJsonPath('feedback.toast.type', 'error')
        ->assertJsonPath('feedback.toast.message', '「示範商品 001」是組合商品「示範商品 064」的內容，請先從組合中移除再刪除。');
    deleteProducts($this, [$id(11)])->assertJsonPath('feedback.toast.type', 'error')
        ->assertJsonPath('feedback.toast.message', '「示範商品 011」是滿額贈「滿額贈示範」的贈品，請先移除贈品再刪除。');
    // A refused row rolls back the whole batch.
    deleteProducts($this, [$id(30), $id(1)])->assertJsonPath('feedback.toast.type', 'error');
    expect(CatalogProduct::count())->toBe($count);

    // Only a removed (trashed) gift row refers to DEMO-015: it does not block deletion.
    PromotionFreebie::create(['action_id' => 1, 'product_id' => $id(15), 'position' => 9])->delete();
    deleteProducts($this, [$id(15)]);
    expect(CatalogProduct::whereKey($id(15))->exists())->toBeFalse();

    deleteProducts($this, [$id(64)]);
    expect(DB::table('catalog_product_components')->where('bundle_id', $id(64))->count())->toBe(0)
        ->and(CatalogProduct::count())->toBe($count - 2);
    deleteProducts($this, [$id(1)]);
    expect(CatalogProduct::where('identifier', 'DEMO-001')->exists())->toBeFalse();
});
