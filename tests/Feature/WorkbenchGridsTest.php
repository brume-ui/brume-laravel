<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\GridShowcaseController;
use Workbench\App\Http\Controllers\OrderController;
use Workbench\App\Models\Order;
use Workbench\App\Models\ShowcaseItem;

use function Orchestra\Testbench\workbench_path;

// The workbench's 訂單 and Grid 示範 grids (their routes are only loaded by the dev server), so
// a protocol or query problem in the demos shows up here and on the mysql job, not only in e2e.

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');

    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])
        ->group(function () {
            Route::brumeResource('orders', OrderController::class)->names('brume.orders');
            Route::brumeResource('grid-showcase', GridShowcaseController::class)->names('brume.grid-showcase');
        });
});

function workbenchEnvelope(string $uri): array
{
    return test()->get($uri)->assertOk()->viewData('page')['props']['envelope'];
}

it('validates 訂單 against PageEnvelope, plain and with every filter applied', function (array $query) {
    Order::factory()->count(20)->create();

    $envelope = workbenchEnvelope('/admin/orders?'.http_build_query($query));

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
})->with([
    'defaults' => [[]],
    'filtered' => [['sort' => '-amount', 'q' => 'BR', 'scope' => 'this_month', 'filter' => [
        'status' => 'paid', 'category' => ['服飾'], 'customer' => 'x',
        'amount' => ['start' => '100', 'end' => '9000'], 'created_at' => ['start' => '2026-01-01'],
        'stage' => ['paid', 'shipped'],
    ]]],
]);

it('serves one 客戶 option per customer name', function () {
    Order::factory()->count(3)->sequence(['customer' => '王小明'], ['customer' => '王小明'], ['customer' => '林美玲'])->create();

    $this->getJson('/admin/orders/_brume/options/customer')->assertOk()
        ->assertJsonPath('data', [['value' => '林美玲', 'label' => '林美玲'], ['value' => '王小明', 'label' => '王小明']]);
});

it('ships paid orders and leaves the others', function () {
    [$paid, $pending] = Order::factory()->count(2)->sequence(['status' => 'paid'], ['status' => 'pending'])->create();

    $this->postJson('/admin/orders/_brume/actions/ship', ['target' => 'row', 'ids' => [$paid->id]])->assertOk()
        ->assertJsonPath('feedback.toast.message', "{$paid->number} 已標記出貨");
    $this->postJson('/admin/orders/_brume/actions/ship', ['target' => 'row', 'ids' => [$pending->id]])->assertForbidden();
    $this->postJson('/admin/orders/_brume/actions/ship', ['target' => 'batch', 'ids' => [$paid->id, $pending->id]])->assertOk()
        ->assertJsonPath('feedback.toast.message', '0 筆訂單已標記出貨');

    expect($paid->fresh()->status)->toBe('shipped')
        ->and($pending->fresh()->status)->toBe('pending');
});

it('validates Grid 示範 against PageEnvelope, plain and with every filter applied', function (array $query) {
    $owner = brumeUser(['username' => 'owner', 'name' => '林負責']);
    ShowcaseItem::factory()->count(25)->create(['owner_id' => $owner->id]);

    $envelope = workbenchEnvelope('/admin/grid-showcase?'.http_build_query($query));

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
})->with([
    'defaults' => [[]],
    'panel filters' => [['filter' => [
        'tags' => ['gift'], 'channels' => ['web'], 'color' => 'teal', 'stock_rule' => ['op' => 'gt', 'value' => '5'],
        'owner' => '2', 'released' => ['start' => '2026-01-01', 'end' => '2026-12-31'], 'owner_id' => '2',
    ]]],
    'header filters' => [['filter' => [
        'sku' => 'SKU', 'note' => '補貨', 'description' => '手工', 'category' => 'kitchen', 'status' => ['paid'],
        'warehouse' => ['taipei'], 'price' => ['start' => '100'], 'stock' => '1', 'progress' => '90', 'rating' => '5',
        'views' => '10',
    ]]],
]);

it('shows each Grid 示範 owner through the eager-loaded relation, one query for all rows', function () {
    $owners = [brumeUser(['username' => 'a', 'name' => '王負責']), brumeUser(['username' => 'b', 'name' => '林負責'])];
    foreach (range(0, 19) as $i) {
        ShowcaseItem::factory()->create(['owner_id' => $owners[$i % 2]->id]);
    }

    DB::enableQueryLog();
    $rows = workbenchEnvelope('/admin/grid-showcase')['page']['rows'];
    $ownerQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'brume_users') && str_contains($q['query'], ' in ('));

    expect($rows)->toHaveCount(20)
        ->and(array_count_values(array_column($rows, 'owner.name')))->toEqual(['王負責' => 10, '林負責' => 10])
        // with('owner'): one whereIn for the page, not one query per row.
        ->and($ownerQueries)->toHaveCount(1);
});

it('answers a broken Grid 示範 edit with a Chinese validation message', function () {
    // The dev server's WorkbenchServiceProvider adds this path; Pest keeps the package defaults.
    app('translation.loader')->addPath(workbench_path('lang'));
    $item = ShowcaseItem::factory()->create();

    $this->patchJson("/admin/grid-showcase/_brume/cells/{$item->id}", ['column' => 'slogan', 'value' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('errors.slogan.0', '請填寫標語。');
});
