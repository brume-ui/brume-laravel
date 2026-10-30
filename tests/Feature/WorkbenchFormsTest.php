<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Http\Controllers\GridShowcaseController;
use Workbench\App\Http\Controllers\OrderController;
use Workbench\App\Models\Order;
use Workbench\App\Models\ShowcaseItem;
use Workbench\App\Providers\WorkbenchServiceProvider;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('orders', OrderController::class)->names('brume.orders');
        Route::brumeResource('grid-showcase', GridShowcaseController::class)->names('brume.grid-showcase');
    });
});

it('serves real order modal fields and persists normal and quick creates', function () {
    $this->getJson('/admin/orders/create', ['X-Brume-Form' => '1'])->assertOk()->assertJsonPath('page.presentation', 'modal')->assertJsonPath('page.after.options', ['list', 'edit', 'create']);
    $this->postJson('/admin/orders', ['values' => ['customer' => '', 'amount' => '100']])->assertUnprocessable()->assertJsonValidationErrors('customer');
    $this->postJson('/admin/orders', ['values' => ['customer' => '表單客戶', 'amount' => '1250', 'status' => 'paid', 'category' => '家居', 'note' => '電話確認']])->assertOk()->assertJsonPath('feedback.toast.message', '已新增訂單');
    $order = Order::query()->where('customer', '表單客戶')->firstOrFail();
    expect($order->number)->not->toBeEmpty();
    expect($order->note)->toBe('電話確認');
    $this->postJson('/admin/orders/_brume/quick-create', ['values' => ['customer' => '快速客戶', 'amount' => '80']])->assertOk();
    expect(Order::query()->where('customer', '快速客戶')->firstOrFail()->status)->toBe('pending');
});

it('requires a cancellation reason and restricts the action to open orders', function () {
    $order = Order::factory()->create(['status' => 'paid']);
    $body = ['target' => 'row', 'ids' => [$order->id]];
    $this->postJson('/admin/orders/_brume/actions/cancel', $body + ['input' => ['reason' => '']])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/admin/orders/_brume/actions/cancel', $body + ['input' => ['reason' => '客戶取消']])->assertOk();
    expect($order->fresh()->status)->toBe('cancelled')->and($order->fresh()->note)->toContain('客戶取消');
    $this->postJson('/admin/orders/_brume/actions/cancel', $body + ['input' => ['reason' => '再次取消']])->assertForbidden();
});

it('serves the four showcase sections and protects featured names and hidden passwords', function () {
    $create = $this->getJson('/admin/grid-showcase/create', ['X-Brume-Form' => '1'])->assertOk()->assertJsonPath('page.presentation', 'page');
    expect(array_column($create->json('page.form.nodes'), 'title'))->toBe(['基本資料', '銷售', '媒體', '其他']);
    $item = ShowcaseItem::factory()->create(['is_featured' => true, 'secret_note' => 'hidden secret']);
    $edit = $this->getJson("/admin/grid-showcase/{$item->id}/edit", ['X-Brume-Form' => '1'])->assertOk()->assertJsonPath('page.values.secret_note', '');
    expect($edit->getContent())->not->toContain('hidden secret');
    expect($edit->json('page.form.nodes.0.children.0.field.readOnly'))->toBeTrue();
    $this->putJson("/admin/grid-showcase/{$item->id}", ['values' => array_replace($edit->json('page.values'), ['name' => 'tamper', 'sku' => 'tamper', 'secret_note' => '', 'tags' => ['custom'], 'price' => '1280.50'])])->assertOk();
    expect($item->fresh()->name)->toBe($item->name)->and($item->fresh()->secret_note)->toBe('hidden secret')->and($item->fresh()->tags)->toBe(['custom'])->and($item->fresh()->price)->toBe('1280.50');
});

it('creates a showcase with complete model defaults, validates unique sku and edits fractional prices', function () {
    $item = ShowcaseItem::factory()->create(['is_featured' => false]);
    $values = ['name' => '測試新品', 'sku' => $item->sku, 'slogan' => '每日好用', 'category' => 'kitchen', 'status' => 'pending', 'price' => '1280.50', 'stock' => 2, 'rating' => '4.5', 'is_active' => true, 'tags' => ['gift'], 'released_at' => '2026-10-31', 'period' => ['start' => '2026-11-01', 'end' => '2026-11-30'], 'meta' => ['color' => 'teal', 'note' => '附註']];
    $this->postJson('/admin/grid-showcase', ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('sku');
    $values['sku'] = 'TASK22-NEW';
    $this->postJson('/admin/grid-showcase', ['values' => $values])->assertOk()->assertJsonPath('urls.view', '/admin/grid-showcase?q=TASK22-NEW');
    $saved = ShowcaseItem::query()->where('sku', 'TASK22-NEW')->firstOrFail();
    expect($saved->starts_at->format('Y-m-d'))->toBe('2026-11-01')->and($saved->meta)->toMatchArray(['color' => 'teal', 'note' => '附註']);
    $this->patchJson("/admin/grid-showcase/_brume/cells/{$saved->id}", ['column' => 'price', 'value' => '1280.5'])->assertOk()->assertJsonPath('row.price', '1,280.50');
});

it('archives demo cover gallery and documents and reloads exact gallery order and extras', function () {
    // Exercise actual dev-server config application; do not bypass it with test-only defaults.
    app()->instance('env', 'local');
    try {
        (new WorkbenchServiceProvider(app()))->register();
    } finally {
        app()->instance('env', 'testing');
    }
    expect(config('brume.uploads.disk'))->toBe('brume_demo');
    Storage::fake('brume_demo');
    $values = $this->getJson('/admin/grid-showcase/create', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $tokens = [];
    foreach (['cover', 'gallery', 'gallery', 'documents'] as $i => $field) {
        $file = $field === 'documents' ? UploadedFile::fake()->createWithContent('readme.txt', 'demo document') : UploadedFile::fake()->image("image{$i}.png");
        $tokens[] = $this->post('/admin/grid-showcase/_brume/upload', ['field' => $field, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json('token');
    }
    $values = array_replace($values, ['name' => '上傳商品', 'sku' => 'TASK22-UPLOAD', 'slogan' => '真實保存', 'cover' => ['token' => $tokens[0]], 'gallery' => [['token' => $tokens[2], 'extra' => ['link' => 'https://example.test/second']], ['token' => $tokens[1], 'extra' => ['link' => 'https://example.test/first']]], 'documents' => [['token' => $tokens[3]]]]);
    $id = $this->postJson('/admin/grid-showcase', ['values' => $values])->assertOk()->json('id');
    $saved = ShowcaseItem::findOrFail($id);
    expect(array_column(array_column($saved->gallery, 'extra'), 'link'))->toBe(['https://example.test/second', 'https://example.test/first']);
    foreach ([$saved->cover, ...array_column($saved->gallery, 'path'), ...$saved->documents] as $path) {
        Storage::disk('brume_demo')->assertExists($path);
    }
    $loaded = $this->getJson("/admin/grid-showcase/{$id}/edit", ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    expect(array_column($loaded['gallery'], 'path'))->toBe(array_column($saved->gallery, 'path'));
    expect(array_column(array_column($loaded['gallery'], 'extra'), 'link'))->toBe(['https://example.test/second', 'https://example.test/first']);
    expect($loaded['cover']['url'])->toBeString();
});

it('returns a field error for a cleared showcase rating on a complete submission', function (string $mode) {
    $item = $mode === 'edit' ? ShowcaseItem::factory()->create(['is_featured' => false, 'rating' => '3.5']) : null;
    $url = $item === null ? '/admin/grid-showcase/create' : "/admin/grid-showcase/{$item->id}/edit";
    $values = $this->getJson($url, ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $values = array_replace($values, ['name' => '評分驗收商品', 'sku' => 'TASK22-NULL-RATING', 'slogan' => '完整表單', 'rating' => null]);
    $before = ShowcaseItem::query()->count();
    $response = $item === null
        ? $this->postJson('/admin/grid-showcase', ['values' => $values])
        : $this->putJson("/admin/grid-showcase/{$item->id}", ['values' => $values]);
    $response->assertUnprocessable()->assertJsonValidationErrors('rating');
    expect(array_keys($response->json('errors')))->toBe(['rating'])
        ->and(ShowcaseItem::query()->count())->toBe($before);
    if ($item !== null) {
        expect($item->fresh()->rating)->toBe('3.5')->and($item->fresh()->sku)->toBe($item->sku);
    }
})->with(['create', 'edit']);

it('saves an optional empty showcase color and serializes the actual grid reload', function (string $mode) {
    $item = $mode === 'edit' ? ShowcaseItem::factory()->create(['is_featured' => false]) : null;
    $url = $item === null ? '/admin/grid-showcase/create' : "/admin/grid-showcase/{$item->id}/edit";
    $values = $this->getJson($url, ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $values = array_replace($values, ['name' => '無顏色驗收商品', 'sku' => 'TASK22-NULL-COLOR', 'slogan' => '完整表單']);
    $values['meta']['color'] = null;
    $response = $item === null
        ? $this->postJson('/admin/grid-showcase', ['values' => $values])
        : $this->putJson("/admin/grid-showcase/{$item->id}", ['values' => $values]);
    $id = $response->assertOk()->json('id');
    expect(ShowcaseItem::findOrFail($id)->meta['color'])->toBeNull();
    $page = $this->get('/admin/grid-showcase?q=TASK22-NULL-COLOR')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page['rows'])->toHaveCount(1)
        ->and($page['rows'][0]['id'])->toBe($id)
        ->and($page['rows'][0]['meta.color'])->toBeNull()
        ->and($page['rows'][0]['_meta']['content']['specs']['rows'][2])->toBe(['label' => '顏色', 'value' => '—']);
})->with(['create', 'edit']);
