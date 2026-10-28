<?php

use Brume\Grid\Column;
use Brume\Grid\Content;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

function displayColumn(string $key = 'status'): Column
{
    return new Column($key, 'Label');
}

function displayOrder(array $attributes = []): Order
{
    return (new Order)->forceFill([
        'id' => 7, 'number' => 'BR-007', 'customer' => 'Ada', 'amount' => 1200, 'status' => 'paid',
        'category' => '服飾', ...$attributes,
    ]);
}

/**
 * Orders on a Route::brumeGrid page with every display and every inline editor.
 */
function displayGrid(): Grid
{
    $grid = Grid::make(Order::class)->title('Orders');

    $grid->column('number')->primary()->copyable();
    $grid->column('customer')->editable(Editor::text(20), 'required|max:20')->display(fn ($v) => strtoupper($v));
    $grid->column('note')->editable(Editor::textarea())->limit(10)->display(fn ($v, $row) => "{$row->customer} 的備註");
    $grid->column('amount')->numeric()->editable(Editor::number(0, 99999, 10));
    $grid->column('status')->label(Order::STATUS_LABELS + ['cancelled' => ['已取消', 'danger']], 'info')
        ->editable(Editor::select(Order::STATUS_LABELS));
    $grid->column('category')->badge('accent')->editable(Editor::radio(array_combine(Order::CATEGORIES, Order::CATEGORIES)));
    $grid->column('tags')->editable(Editor::checkbox([1 => 'A', 2 => 'B']))->display(fn () => ['x', 'y'])->label();
    $grid->column('link')->link(fn (Order $row) => "/admin/orders/{$row->id}", external: true)->display(fn ($v, $row) => $row->number);
    $grid->column('open')->button(fn (Order $row) => "/admin/orders/{$row->id}/edit")->display(fn () => '開啟');
    $grid->column('photo')->image(64, 48)->display(fn () => ['https://example.test/a.png']);
    $grid->column('progress')->progressBar(2000)->display(fn ($v, $row) => $row->amount);
    $grid->column('qr')->qrcode(120)->display(fn ($v, $row) => $row->number);
    $grid->column('file')->downloadable()->display(fn () => 'https://example.test/a.pdf');
    $grid->column('detail')->expand(fn (Order $row) => Content::text("訂單 {$row->number}"), '明細');
    $grid->column('items')->modal(
        fn (Order $row) => Content::table(['sku' => '品號', 'qty' => '數量'], [['sku' => 'A1', 'qty' => 2, 'cost' => 9]]),
        '品項', '查看',
    );
    $grid->column('widget')->expand(fn (Order $row) => Content::component('OrderTimeline', ['id' => $row->id]));
    $grid->column('lines')->table(['sku' => '品號', 'qty' => '數量'])->display(fn () => [['sku' => 'A1', 'qty' => 2]]);
    $grid->column('paid')->switch('boolean', '已付', '未付')->display(fn ($v, $row) => $row->status === 'paid');
    $grid->column('flags')->switchGroup(['is_featured' => '精選', 'is_new' => '新品']);

    return $grid;
}

it('declares each display and editor as its schema fragment', function (Closure $declare, array $fragment) {
    $column = displayColumn();
    $declare($column);

    expect($column->toSchema())->toBe(['key' => 'status', 'label' => 'Label', ...$fragment]);
})->with([
    'label, plain options' => [fn (Column $c) => $c->label(['paid' => '已付款', 1 => '一']), ['display' => [
        'type' => 'label', 'options' => ['paid' => ['label' => '已付款', 'tone' => 'neutral'], '1' => ['label' => '一', 'tone' => 'neutral']],
    ]]],
    'label, toned options and default tone' => [fn (Column $c) => $c->label(['paid' => '已付款', 'cancelled' => ['已取消', 'danger']], 'success'), ['display' => [
        'type' => 'label', 'options' => ['paid' => ['label' => '已付款', 'tone' => 'success'], 'cancelled' => ['label' => '已取消', 'tone' => 'danger']],
    ]]],
    'label without options' => [fn (Column $c) => $c->label(), ['display' => ['type' => 'label', 'tone' => 'neutral']]],
    'label without options, toned' => [fn (Column $c) => $c->label(tone: 'info'), ['display' => ['type' => 'label', 'tone' => 'info']]],
    'badge' => [fn (Column $c) => $c->badge(), ['display' => ['type' => 'badge', 'tone' => 'neutral']]],
    'badge, toned' => [fn (Column $c) => $c->badge('warning'), ['display' => ['type' => 'badge', 'tone' => 'warning']]],
    'link' => [fn (Column $c) => $c->link(fn () => '/x'), ['display' => ['type' => 'link']]],
    'link, external' => [fn (Column $c) => $c->link(fn () => '/x', true), ['display' => ['type' => 'link', 'external' => true]]],
    'button' => [fn (Column $c) => $c->button(fn () => '/x'), ['display' => ['type' => 'link', 'style' => 'button']]],
    'image' => [fn (Column $c) => $c->image(), ['display' => ['type' => 'image']]],
    'image, sized' => [fn (Column $c) => $c->image(80, 60), ['display' => ['type' => 'image', 'width' => 80, 'height' => 60]]],
    'limit' => [fn (Column $c) => $c->limit(20), ['display' => ['type' => 'limit', 'length' => 20]]],
    'progressBar' => [fn (Column $c) => $c->progressBar(), ['display' => ['type' => 'progress', 'max' => 100]]],
    'progressBar, max' => [fn (Column $c) => $c->progressBar(500), ['display' => ['type' => 'progress', 'max' => 500]]],
    'qrcode' => [fn (Column $c) => $c->qrcode(), ['display' => ['type' => 'qrcode']]],
    'qrcode, sized' => [fn (Column $c) => $c->qrcode(150), ['display' => ['type' => 'qrcode', 'size' => 150]]],
    'copyable' => [fn (Column $c) => $c->copyable(), ['display' => ['type' => 'copyable']]],
    'downloadable' => [fn (Column $c) => $c->downloadable(), ['display' => ['type' => 'downloadable']]],
    'expand' => [fn (Column $c) => $c->expand(fn () => Content::text('x')), ['display' => ['type' => 'expand']]],
    'expand, labelled' => [fn (Column $c) => $c->expand(fn () => Content::text('x'), '明細'), ['display' => ['type' => 'expand', 'label' => '明細']]],
    'modal' => [fn (Column $c) => $c->modal(fn () => Content::text('x')), ['display' => ['type' => 'modal']]],
    'modal, titled and labelled' => [fn (Column $c) => $c->modal(fn () => Content::text('x'), '訂單明細', '查看'), ['display' => ['type' => 'modal', 'label' => '查看', 'title' => '訂單明細']]],
    'table' => [fn (Column $c) => $c->table(['sku' => '品號', 'qty' => '數量']), ['display' => ['type' => 'table', 'columns' => [
        ['key' => 'sku', 'label' => '品號'], ['key' => 'qty', 'label' => '數量'],
    ]]]],
    'component' => [fn (Column $c) => $c->component('OrderStatus', ['tone' => 'info']), ['display' => ['type' => 'component', 'name' => 'OrderStatus', 'props' => ['tone' => 'info']]]],
    'editable (text by default)' => [fn (Column $c) => $c->editable(), ['editor' => ['type' => 'text']]],
    'editable text, max length' => [fn (Column $c) => $c->editable(Editor::text(30)), ['editor' => ['type' => 'text', 'maxLength' => 30]]],
    'editable textarea' => [fn (Column $c) => $c->editable(Editor::textarea()), ['editor' => ['type' => 'textarea']]],
    'editable number' => [fn (Column $c) => $c->editable(Editor::number()), ['editor' => ['type' => 'number']]],
    'editable number, bounded' => [fn (Column $c) => $c->editable(Editor::number(0, 10.5, 0.5)), ['editor' => ['type' => 'number', 'min' => 0.0, 'max' => 10.5, 'step' => 0.5]]],
    'editable select' => [fn (Column $c) => $c->editable(Editor::select(['paid' => '已付款', 2 => '二'])), ['editor' => ['type' => 'select', 'options' => [
        ['value' => 'paid', 'label' => '已付款'], ['value' => '2', 'label' => '二'],
    ]]]],
    'editable radio' => [fn (Column $c) => $c->editable(Editor::radio([1 => '是', 0 => '否'])), ['editor' => ['type' => 'radio', 'options' => [
        ['value' => '1', 'label' => '是'], ['value' => '0', 'label' => '否'],
    ]]]],
    'editable checkbox' => [fn (Column $c) => $c->editable(Editor::checkbox(['a' => 'A'])), ['editor' => ['type' => 'checkbox', 'options' => [
        ['value' => 'a', 'label' => 'A'],
    ]]]],
    'switch' => [fn (Column $c) => $c->switch(), ['editor' => ['type' => 'switch']]],
    'switch, labelled' => [fn (Column $c) => $c->switch('boolean', '上架', '下架'), ['editor' => ['type' => 'switch', 'onLabel' => '上架', 'offLabel' => '下架']]],
    'switchGroup' => [fn (Column $c) => $c->switchGroup(['is_featured' => '精選', 'is_new' => '新品']), ['editor' => ['type' => 'switchGroup', 'keys' => [
        ['key' => 'is_featured', 'label' => '精選'], ['key' => 'is_new', 'label' => '新品'],
    ]]]],
    'display and editor together' => [fn (Column $c) => $c->badge('info')->editable(Editor::select(['paid' => '已付款'])), [
        'display' => ['type' => 'badge', 'tone' => 'info'],
        'editor' => ['type' => 'select', 'options' => [['value' => 'paid', 'label' => '已付款']]],
    ]],
]);

it('sends label options keyed 0, 1, ... as a JSON object', function () {
    $schema = displayColumn()->label([0 => '否', 1 => ['是', 'success']])->toSchema();

    expect(json_encode($schema['display'], JSON_UNESCAPED_UNICODE))
        ->toBe('{"type":"label","options":{"0":{"label":"否","tone":"neutral"},"1":{"label":"是","tone":"success"}}}');
});

it('keeps the editable rules for the cell endpoint', function () {
    expect(displayColumn()->editable(rules: 'required|max:5')->editRules())->toBe('required|max:5')
        ->and(displayColumn()->switch(['boolean'])->editRules())->toBe(['boolean'])
        ->and(displayColumn()->switchGroup(['a' => 'A'])->editRules())->toBe([])
        ->and(displayColumn()->editRules())->toBe([])
        ->and(displayColumn()->isEditable())->toBeFalse()
        ->and(displayColumn()->switch()->isEditable())->toBeTrue();
});

it('sends link and button hrefs in _meta.href', function () {
    $order = displayOrder();
    $link = displayColumn('number')->link(fn (Order $row) => "/admin/orders/{$row->id}");
    $button = displayColumn('number')->button(fn (Order $row) => null);

    expect($link->value($order))->toBe('BR-007')
        ->and($link->rowMeta($order))->toBe(['href' => '/admin/orders/7'])
        // No href: the cell shows its text and _meta leaves the key out.
        ->and($button->rowMeta($order))->toBe([]);
});

it('computes expand and modal content into _meta.content', function () {
    $order = displayOrder();

    expect(displayColumn('detail')->expand(fn (Order $row) => Content::text("訂單 {$row->number}"))->rowMeta($order))
        ->toBe(['content' => ['type' => 'text', 'text' => '訂單 BR-007']])
        ->and(displayColumn('detail')->modal(fn (Order $row) => Content::component('Timeline', ['id' => $row->id]))->rowMeta($order))
        ->toBe(['content' => ['type' => 'component', 'name' => 'Timeline', 'props' => ['id' => 7]]])
        ->and(displayColumn('detail')->modal(fn () => null)->rowMeta($order))->toBe([]);
});

it('sends the raw value of an edited column with a display closure in _meta.raw', function () {
    $order = displayOrder();
    $displayed = displayColumn('customer')->editable()->display(fn ($value) => "客戶 {$value}");

    expect($displayed->value($order))->toBe('客戶 Ada')
        ->and($displayed->rowMeta($order))->toBe(['raw' => 'Ada'])
        // A null value still goes out, so the editor does not start from the display text.
        ->and($displayed->rowMeta(displayOrder(['customer' => null])))->toBe(['raw' => null])
        // Not edited, or edited without a closure: nothing to add.
        ->and(displayColumn('customer')->display(fn ($value) => "客戶 {$value}")->rowMeta($order))->toBe([])
        ->and(displayColumn('customer')->editable()->rowMeta($order))->toBe([]);
});

it('sends switch values as booleans', function () {
    $order = displayOrder(['is_featured' => 1, 'is_new' => '0']);
    $switch = displayColumn('is_featured')->switch();
    $displayed = displayColumn('is_new')->switch()->display(fn ($on) => $on ? '新品' : '一般');

    expect($switch->value($order))->toBeTrue()
        ->and($displayed->value($order))->toBe('一般')
        ->and($displayed->rowMeta($order))->toBe(['raw' => false]);
});

it('sends a switchGroup value as one boolean per declared key', function () {
    $order = displayOrder(['is_featured' => 1, 'is_new' => null, 'secret' => true]);
    $group = displayColumn('flags')->switchGroup(['is_featured' => '精選', 'is_new' => '新品']);
    $displayed = displayColumn('flags')->switchGroup(['is_featured' => '精選'])
        ->display(fn (array $flags) => $flags['is_featured'] ? '精選' : '');

    expect($group->value($order))->toBe(['is_featured' => true, 'is_new' => false])
        ->and($group->rowMeta($order))->toBe([])
        ->and($displayed->value($order))->toBe('精選')
        ->and($displayed->rowMeta($order))->toBe(['raw' => ['is_featured' => true]]);
});

it('builds Content', function () {
    expect(Content::text('說明')->toArray())->toBe(['type' => 'text', 'text' => '說明'])
        ->and(Content::component('Timeline')->toArray())->toBe(['type' => 'component', 'name' => 'Timeline']);
});

it('outputs only the declared columns of Content::table rows', function () {
    $content = Content::table(['sku' => '品號', 'qty' => '數量'], collect([
        ['sku' => 'A1', 'qty' => 2, 'cost' => 9],
        displayOrder(['sku' => 'B2', 'qty' => 1]),
        (object) ['sku' => 'C3'],
    ]));

    expect($content->toArray())->toBe([
        'type' => 'table',
        'columns' => [['key' => 'sku', 'label' => '品號'], ['key' => 'qty', 'label' => '數量']],
        'rows' => [['sku' => 'A1', 'qty' => 2], ['sku' => 'B2', 'qty' => 1], ['sku' => 'C3', 'qty' => null]],
    ]);
});

it('outputs only the declared columns of a table display', function () {
    $order = displayOrder(['lines' => [['sku' => 'A1', 'qty' => 2, 'cost' => 9]]]);

    expect(displayColumn('lines')->table(['sku' => '品號'])->value($order))->toBe([['sku' => 'A1']])
        ->and(displayColumn('lines')->table(['qty' => '數量'])->display(fn () => collect([['qty' => 3, 'x' => 1]]))->value($order))
        ->toBe([['qty' => 3]]);
});

it('lets a display closure return a list for label, image, downloadable and table only', function () {
    $order = displayOrder();

    expect(displayColumn()->label()->display(fn () => ['a', 'b'])->value($order))->toBe(['a', 'b'])
        ->and(displayColumn()->image()->display(fn () => ['/a.png'])->value($order))->toBe(['/a.png'])
        ->and(displayColumn()->downloadable()->display(fn () => [['url' => '/a.pdf', 'name' => 'A']])->value($order))
        ->toBe([['url' => '/a.pdf', 'name' => 'A']]);

    displayColumn()->badge()->display(fn () => ['a'])->value($order);
})->throws(InvalidArgumentException::class, 'display()');

it('rejects a component and a display on the same column', function (Closure $declare) {
    $declare(displayColumn());
})->throws(LogicException::class, '顯示')->with([
    'display, then component' => [fn (Column $c) => $c->badge()->component('X')],
    'component, then display' => [fn (Column $c) => $c->component('X')->label()],
    'two displays' => [fn (Column $c) => $c->badge()->copyable()],
]);

it('rejects invalid declarations', function (Closure $declare) {
    $declare(displayColumn());
})->throws(InvalidArgumentException::class)->with([
    'unknown tone' => [fn (Column $c) => $c->badge('red')],
    'unknown option tone' => [fn (Column $c) => $c->label(['a' => ['A', 'red']])],
    'malformed option' => [fn (Column $c) => $c->label(['a' => ['A']])],
    'limit below 1' => [fn (Column $c) => $c->limit(0)],
    'progress max below 1' => [fn (Column $c) => $c->progressBar(0)],
    'empty table columns' => [fn (Column $c) => $c->table([])],
    'empty select options' => [fn (Column $c) => $c->editable(Editor::select([]))],
    'empty switchGroup' => [fn (Column $c) => $c->switchGroup([])],
    'switchGroup keys as a list' => [fn (Column $c) => $c->switchGroup(['精選', '新品'])],
]);

it('requires Route::brumeGrid for an editable column in local', function () {
    app()->detectEnvironment(fn () => 'local');
    $grid = Grid::make(Order::class)->title('訂單');
    $grid->column('customer')->editable();

    $grid->toSchema(Request::create('/admin/orders'));
})->throws(LogicException::class, 'Route::brumeGrid');

it('keeps the export declarations for the exporter', function () {
    $using = fn ($value) => "#{$value}";
    $column = displayColumn()->exportUsing($using);

    expect($column->isExportable())->toBeTrue()
        ->and($column->exportCallback())->toBe($using)
        ->and(displayColumn()->exportable(false)->isExportable())->toBeFalse();
});

it('validates a grid with every display and editor against PageEnvelope', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])
        ->group(fn () => Route::brumeGrid('orders', fn () => displayGrid())->name('brume.orders.index'));
    $order = Order::factory()->create(['number' => 'BR-001', 'customer' => 'ada', 'status' => 'paid']);

    $envelope = $this->get('/admin/orders')->assertOk()->viewData('page')['props']['envelope'];
    $row = $envelope['page']['rows'][0];

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([])
        ->and($row['customer'])->toBe('ADA')
        ->and($row['flags'])->toBe(['is_featured' => false, 'is_new' => false])
        ->and($row['_meta'])->toBe([
            'href' => ['link' => "/admin/orders/{$order->id}", 'open' => "/admin/orders/{$order->id}/edit"],
            'content' => [
                'detail' => ['type' => 'text', 'text' => '訂單 BR-001'],
                'items' => ['type' => 'table', 'columns' => [['key' => 'sku', 'label' => '品號'], ['key' => 'qty', 'label' => '數量']], 'rows' => [['sku' => 'A1', 'qty' => 2]]],
                'widget' => ['type' => 'component', 'name' => 'OrderTimeline', 'props' => ['id' => $order->id]],
            ],
            'raw' => ['customer' => 'ada', 'note' => null, 'tags' => null, 'paid' => false],
        ])
        ->and($envelope['page']['endpoints']['cell'])->toBe('/admin/orders/_brume/cells/{id}');
});
