<?php

use Brume\Html\HtmlSanitizer;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
    expect(array_column($form->json('page.form.nodes'), 'title'))->toBe(['基本資料', '商品介紹', '圖片', '預購', '銷售與限購', '組合內容']);
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

function productEnvelope($test, string $url): array
{
    return $test->getJson($url, ['X-Brume-Form' => '1'])->assertOk()->json();
}

it('declares the rich text editors and their FAQ repeater, and the edit page validates against PageEnvelope', function () {
    $product = CatalogProduct::where('identifier', 'DEMO-001')->firstOrFail();
    $envelope = productEnvelope($this, "/admin/products/{$product->id}/edit");
    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
    expect(JsonSchema::errors(productEnvelope($this, '/admin/products/create'), 'PageEnvelope'))->toBe([]);

    $fields = [];
    $walk = function (array $nodes) use (&$walk, &$fields) {
        foreach ($nodes as $node) {
            if (isset($node['field']['name'])) {
                $fields[$node['field']['name']] = $node['field'];
                $walk($node['field']['nodes'] ?? []);
            }
            $walk($node['children'] ?? []);
        }
    };
    $walk($envelope['page']['form']['nodes']);

    expect($fields['description'])->toMatchArray(['type' => 'richText', 'height' => 520, 'contentCss' => ['/storefront/content.css']])
        ->and($fields['description']['images'])->toBe(['accept' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'maxSize' => 2048 * 1024])
        ->and($fields['erp_note'])->toMatchArray(['type' => 'richText', 'readOnly' => true])
        ->and($fields['erp_note'])->not->toHaveKey('images')
        ->and($fields['faqs']['type'])->toBe('repeater');
    expect($fields['answer'])->toMatchArray(['type' => 'richText', 'required' => true])->and($fields['answer'])->not->toHaveKey('images');
});

it('seeds the storefront sample, FAQ rows and ERP notes idempotently and sends them sanitised', function () {
    $this->seed(CatalogSeeder::class);
    $clean = app(HtmlSanitizer::class);
    $sample = CatalogSeeder::storefrontSample();
    expect($sample)->toContain('<table')->toContain('youtube.com/embed/')->toContain('<ol>')->toContain('/storefront/images/');

    foreach (range(1, CatalogSeeder::PRODUCTS) as $i) {
        $product = CatalogProduct::where('identifier', sprintf('DEMO-%03d', $i))->firstOrFail();
        expect($product->description)->toBe(in_array($i, CatalogSeeder::RICH_TEXT_SAMPLE, true) ? $sample : null)
            ->and($product->faqs === null)->toBe(! in_array($i, CatalogSeeder::RICH_TEXT_FAQS, true))
            ->and($product->erp_note === null)->toBe(! in_array($i, CatalogSeeder::RICH_TEXT_ERP_NOTE, true));
    }

    $product = CatalogProduct::where('identifier', 'DEMO-005')->firstOrFail();
    $values = productValues($this, "/admin/products/{$product->id}/edit");
    expect($values['description'])->toBe($clean->clean($sample))
        ->and($values['erp_note'])->toBe($clean->clean($product->erp_note));
    $faqs = productValues($this, '/admin/products/'.CatalogProduct::where('identifier', 'DEMO-001')->value('id').'/edit')['faqs'];
    expect(array_column($faqs, 'question'))->toBe(['敏感肌可以使用嗎？', '開封後可以放多久？'])
        ->and($faqs[1]['answer'])->toBe($clean->clean(CatalogSeeder::richText(1)['faqs'][1]['answer']));
});

it('stores the untouched seeded sample as the sanitiser cleans it, and saving again changes nothing', function () {
    $product = CatalogProduct::where('identifier', 'DEMO-001')->firstOrFail();
    $url = "/admin/products/{$product->id}";
    $this->putJson($url, ['values' => productValues($this, "{$url}/edit")])->assertOk();
    $stored = $product->fresh()->description;
    expect($stored)->toBe(app(HtmlSanitizer::class)->clean(CatalogSeeder::storefrontSample()));

    $this->putJson($url, ['values' => productValues($this, "{$url}/edit")])->assertOk();
    expect($product->fresh()->description)->toBe($stored);
});

it('sanitises the description and FAQ answers on save, requires an answer and ignores the read-only ERP note', function () {
    $product = CatalogProduct::where('identifier', 'DEMO-003')->firstOrFail();
    $url = "/admin/products/{$product->id}";
    $values = productValues($this, "{$url}/edit");
    $hostile = '<p>保留</p><script>window.__xss=1</script><img src="x" onerror="window.__xss=1"><iframe src="https://evil.example/"></iframe>';

    $empty = array_replace($values, ['faqs' => [['_brume' => ['key' => 'new-1'], 'question' => '空白回答？', 'answer' => '<p><br></p>']]]);
    $this->putJson($url, ['values' => $empty])->assertUnprocessable()->assertJsonValidationErrors('faqs.0.answer');

    $this->putJson($url, ['values' => array_replace($values, [
        'description' => $hostile,
        'erp_note' => '<p>forged</p>',
        'faqs' => [['_brume' => ['key' => 'new-1'], 'question' => '甲', 'answer' => $hostile], ['_brume' => ['key' => 'new-2'], 'question' => '乙', 'answer' => '<p>第二列<b>獨立</b></p>']],
    ])])->assertOk();

    $fresh = $product->fresh();
    expect($fresh->description)->toBe('<p>保留</p><img src="x">')
        ->and(array_column($fresh->faqs, 'answer'))->toBe(['<p>保留</p><img src="x">', '<p>第二列<b>獨立</b></p>'])
        ->and($fresh->erp_note)->toBe($product->erp_note);
});

it('accepts editor images for the product description and refuses them for the FAQ answer and the ERP note', function () {
    Storage::fake('public');
    $product = CatalogProduct::where('identifier', 'DEMO-002')->firstOrFail();
    $upload = productEnvelope($this, "/admin/products/{$product->id}/edit")['page']['uploadUrl'];
    $post = fn (string $field) => $this->post($upload, ['field' => $field, 'file' => UploadedFile::fake()->image('photo.png', 8, 8)], ['Accept' => 'application/json']);

    $url = $post('description')->assertOk()->json('url');
    expect($url)->toStartWith('/storage/brume/brume.products/editor/')->toEndWith('.png');
    Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));

    $post('erp_note')->assertUnprocessable();
    $post('faqs.0.answer')->assertUnprocessable();
});
