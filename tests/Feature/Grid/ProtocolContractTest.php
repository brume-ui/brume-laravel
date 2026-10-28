<?php

use Brume\BrumeManager;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

/**
 * Orders on a Route::brumeGrid page with every Task 14 and 15 feature declared (remote options
 * aside, so the options endpoint still has nothing to serve).
 */
function protocolGrid(): Grid
{
    $grid = Grid::make(Order::class)
        ->title('Orders')
        ->description('Protocol contract')
        ->quickSearch('number', 'customer')
        ->quickSearchPlaceholder('搜尋訂單編號或客戶')
        ->perPage(15)
        ->defaultSort('created_at', 'desc')
        ->emptyState('還沒有訂單', '新訂單會出現在這裡。', '建立訂單', '/admin/orders/create');

    $grid->column('number')->sortable()->primary()->width('10rem');
    $grid->column('customer')->hideable(false);
    $grid->column('amount')->numeric()->sortable();
    $grid->column('status')->component('OrderStatus');
    $grid->column('category')->hidden()->filter('in', array_combine(Order::CATEGORIES, Order::CATEGORIES));
    $grid->column('created_at')->sortable();

    $grid->filters()->columns(2);
    $grid->filters()->in('status', '狀態')->multipleSelect(Order::STATUS_LABELS);
    $grid->filters()->between('amount', '金額')->number();
    $grid->filters()->newline();
    $grid->filters()->group('amount_cond', '金額條件', column: 'amount')->number()->span(2);
    $grid->filters()->hidden('customer');
    $grid->filters()->selector('status_pick', '快速狀態', Order::STATUS_LABELS, column: 'status');
    $grid->filters()->scope('big', '大單', fn ($query) => $query->where('amount', '>=', 5000));

    return $grid;
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');

    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])
        ->group(fn () => Route::brumeGrid('orders', fn () => protocolGrid())->name('brume.orders.index'));
});

it('emits schema 0.2', function () {
    expect(BrumeManager::SCHEMA_VERSION)->toBe('0.2.0');
});

it('validates a brumeGrid page against PageEnvelope', function (array $query) {
    Order::factory()->count(20)->create();

    $envelope = $this->get('/admin/orders?'.http_build_query($query))->assertOk()
        ->viewData('page')['props']['envelope'];

    expect($envelope['schemaVersion'])->toBe('0.2.0')
        ->and(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
})->with([
    'defaults' => [[]],
    'sorted, searched, paged' => [['sort' => '-amount', 'q' => 'BR', 'per_page' => '50', 'page' => '2']],
    'nothing found' => [['q' => 'no such order']],
    'invalid values' => [['sort' => 'password', 'per_page' => '7', 'page' => '-1', 'q' => ['x']]],
    'filtered and scoped' => [['scope' => 'big', 'filter' => [
        'status' => ['paid', 'shipped'], 'amount' => ['start' => '100'], 'amount_cond' => ['op' => 'gt', 'value' => '1'],
        'customer' => 'x', 'status_pick' => 'paid', 'category' => ['服飾'],
    ]]],
    'invalid filters' => [['scope' => ['x'], 'filter' => ['status' => ['x' => ['y' => '1']], 'amount' => 'abc', 'amount_cond' => ['op' => 'x']]]],
]);

it('validates the Inertia partial reload the grid navigates with', function () {
    Order::factory()->count(3)->create();
    $first = $this->get('/admin/orders')->viewData('page');

    $response = $this->get('/admin/orders?sort=amount', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $first['version'],
        'X-Inertia-Partial-Component' => 'brume/page',
        'X-Inertia-Partial-Data' => 'envelope',
    ])->assertOk();

    // Decoded to objects: the wire format, where an empty filters map must be {} and not [].
    $envelope = json_decode($response->getContent())->props->envelope;

    expect($response->getContent())->toContain('"filters":{}')
        ->and($envelope->page->query->sort->key)->toBe('amount')
        ->and(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
});

it('answers every endpoint with an ErrorResponse while the grid declares nothing for it', function (string $method, string $uri) {
    $response = $this->json($method, $uri, ['target' => 'row', 'ids' => [1]])->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
})->with([
    ['POST', '/admin/orders/_brume/actions/ship'],
    ['PATCH', '/admin/orders/_brume/cells/1'],
    ['GET', '/admin/orders/_brume/export'],
    ['GET', '/admin/orders/_brume/options/customer'],
]);
