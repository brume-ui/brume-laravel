<?php

use Brume\Grid\Column;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * /admin/cells: shop 1's items on a Route::brumeGrid page behind brume.auth, with one column per
 * inline editor. $customer, $status and $tags replace those columns' editors; $declare adds columns.
 */
function cellRoutes(?Closure $declare = null, ?Editor $customer = null, ?Editor $status = null, ?Editor $tags = null): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('cells', function () use ($declare, $customer, $status, $tags) {
            $grid = Grid::make(CellItem::query()->where('shop_id', 1))->title('Cells');
            $grid->column('shop_id', '商店');
            $grid->column('customer', '客戶')->editable($customer, 'required|max:5')->display(fn (string $value) => "客戶 {$value}");
            $grid->column('status', '狀態')->editable($status ?? Editor::select(['open' => '未處理', 'paid' => '已付款', 'done' => '完成']));
            $grid->column('amount', '金額')->numeric()->editable(Editor::number(0, 1000));
            $grid->column('tags', '標籤')->editable($tags ?? Editor::checkbox(['a' => 'A', 'b' => 'B', 'c' => 'C']))
                ->display(fn (?array $tags) => implode(',', $tags ?? []));
            $grid->column('is_active', '啟用')->switch();
            $grid->column('flags', '標記')->switchGroup(['is_featured' => '精選', 'is_new' => '新品']);
            $declare?->__invoke($grid);

            return $grid;
        }));
}

/**
 * Items 1 and 2 in shop 1, item 3 in shop 2.
 *
 * @return array{0: CellItem, 1: CellItem, 2: CellItem}
 */
function cellItems(): array
{
    return [
        CellItem::query()->create(['shop_id' => 1, 'customer' => 'Ada', 'password' => 'secret']),
        CellItem::query()->create(['shop_id' => 1, 'customer' => 'Bea']),
        CellItem::query()->create(['shop_id' => 2, 'customer' => 'Cy']),
    ];
}

/**
 * @param  array<string, mixed>|string  $body  a string is sent as the raw JSON body (json_encode() writes 1.0 as 1)
 */
function patchCell(int|string $id, array|string $body): TestResponse
{
    if (is_string($body)) {
        return test()->call('PATCH', '/admin/cells/_brume/cells/'.$id, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);
    }

    return test()->patchJson('/admin/cells/_brume/cells/'.$id, $body);
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');

    // A project's zh_TW validation messages (Laravel itself ships English only).
    app('translator')->addLines([
        'validation.required' => ':attribute 不能空白。',
        'validation.max.string' => ':attribute 不能超過 :max 個字。',
        'validation.in' => '所選的 :attribute 無效。',
        'validation.boolean' => ':attribute 只能是是或否。',
        'validation.array' => ':attribute 必須是陣列。',
    ], 'zh_TW');
});

it('saves a text cell and answers the recomputed row', function () {
    [$a, $b] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'customer', 'value' => 'Eve'])->assertOk();

    expect(JsonSchema::errors($response->json(), 'CellUpdateResponse'))->toBe([])
        ->and($response->json('row.id'))->toBe($a->id)
        ->and($response->json('row.customer'))->toBe('客戶 Eve')
        ->and($response->json('row._meta.raw.customer'))->toBe('Eve')
        ->and($a->fresh()->customer)->toBe('Eve')
        ->and($b->fresh()->customer)->toBe('Bea');
});

it('answers only the declared columns in the row', function () {
    [$a] = cellItems();
    cellRoutes();

    $row = patchCell($a->id, ['column' => 'status', 'value' => 'paid'])->assertOk()->json('row');

    expect(array_keys($row))->toBe(['id', 'shop_id', 'customer', 'status', 'amount', 'tags', 'is_active', 'flags', '_meta'])
        ->and($row)->not->toHaveKey('password')
        ->and(json_encode($row))->not->toContain('secret');
});

it('answers a failed rule as 422 keyed by the column, with the label in the message', function (mixed $value, string $message) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'customer', 'value' => $value])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('errors'))->toBe(['customer' => [$message]])
        ->and($a->fresh()->customer)->toBe('Ada');
})->with([
    'too long' => ['Evelyn', '客戶 不能超過 5 個字。'],
    'empty' => ['', '客戶 不能空白。'],
    'null' => [null, '客戶 不能空白。'],
]);

it('accepts only the declared options of a select or radio', function (string $type) {
    [$a] = cellItems();
    $options = ['open' => '未處理', 'paid' => '已付款'];
    cellRoutes(status: $type === 'select' ? Editor::select($options) : Editor::radio($options));

    $response = patchCell($a->id, ['column' => 'status', 'value' => 'done'])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('errors'))->toBe(['status' => ['所選的 狀態 無效。']])
        ->and($a->fresh()->status)->toBe('open');

    patchCell($a->id, ['column' => 'status', 'value' => 'paid'])->assertOk()->assertJsonPath('row.status', 'paid');

    expect($a->fresh()->status)->toBe('paid');
})->with(['select', 'radio']);

it('refuses a select value outside the options', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes();

    patchCell($a->id, ['column' => 'status', 'value' => $value])->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($a->fresh()->status)->toBe('open');
})->with([
    'unknown' => ['refunded'],
    'null' => [null],
    'a list' => [['paid']],
]);

it('saves a checkbox list of declared options', function () {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'tags', 'value' => ['a', 'c']])->assertOk();

    expect($response->json('row.tags'))->toBe('a,c')
        ->and($response->json('row._meta.raw.tags'))->toBe(['a', 'c'])
        ->and($a->fresh()->tags)->toBe(['a', 'c']);
});

it('refuses a checkbox value that is not a list of declared options', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'tags', 'value' => $value])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['tags'])
        ->and($a->fresh()->tags)->toBeNull();
})->with([
    'an undeclared option' => [['a', 'z']],
    'a string' => ['a'],
    'an object' => [['x' => 'a']],
]);

it('saves a switch as a boolean', function (mixed $value, bool $saved) {
    [$a] = cellItems();
    $a->update(['is_active' => ! $saved]);
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'is_active', 'value' => $value])->assertOk();

    expect($response->json('row.is_active'))->toBe($saved)
        ->and(CellItem::query()->whereKey($a->id)->value('is_active'))->toEqual($saved ? 1 : 0)
        ->and($a->fresh()->is_active)->toBe($saved);
})->with([
    '"1"' => ['1', true],
    'true' => [true, true],
    '1' => [1, true],
    '"0"' => ['0', false],
    'false' => [false, false],
]);

it('refuses a switch value that is not a boolean', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'is_active', 'value' => $value])->assertUnprocessable();

    expect($response->json('errors'))->toBe(['is_active' => ['啟用 只能是是或否。']])
        ->and($a->fresh()->is_active)->toBeFalse();
})->with(['yes', 2, null]);

it('saves both attributes of a switchGroup at once', function () {
    [$a] = cellItems();
    $a->update(['is_new' => true]);
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'flags', 'value' => ['is_featured' => true, 'is_new' => false]])->assertOk();

    expect(JsonSchema::errors($response->json(), 'CellUpdateResponse'))->toBe([])
        ->and($response->json('row.flags'))->toBe(['is_featured' => true, 'is_new' => false])
        ->and($a->fresh()->only('is_featured', 'is_new'))->toBe(['is_featured' => true, 'is_new' => false]);
});

it('refuses a switchGroup value with an undeclared or non-boolean attribute', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => 'flags', 'value' => $value])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and(array_keys($response->json('errors')))->toBe(['flags'])
        ->and($a->fresh()->only('is_featured', 'is_new', 'password'))->toBe(['is_featured' => false, 'is_new' => false, 'password' => 'secret']);
})->with([
    'an undeclared attribute' => [['is_featured' => true, 'password' => 'x']],
    'only an undeclared attribute' => [['shop_id' => 2]],
    'a non-boolean' => [['is_featured' => 'maybe']],
    'a list' => [[true, false]],
    'empty' => [[]],
    'a scalar' => [true],
]);

it('checks a number against the editor bounds', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes();

    patchCell($a->id, ['column' => 'amount', 'value' => $value])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    expect($a->fresh()->amount)->toBe(0);
})->with(['abc', -1, 1001, [[5]]]);

it('checks a text against the editor maximum length', function (mixed $value) {
    [$a] = cellItems();
    cellRoutes(customer: Editor::text(3));

    patchCell($a->id, ['column' => 'customer', 'value' => $value])->assertUnprocessable()
        ->assertJsonValidationErrors(['customer']);

    expect($a->fresh()->customer)->toBe('Ada');
})->with([
    'longer than the editor allows' => ['Evel'],
    'a list' => [['Eve']],
]);

it('answers 422 for a column that cannot be edited inline', function (string $column) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, ['column' => $column, 'value' => 2])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('errors'))->toBe(['column' => ['這個欄位不能直接編輯。']])
        ->and($a->fresh()->shop_id)->toBe(1);
})->with([
    'a declared, not editable column' => ['shop_id'],
    'an undeclared attribute' => ['password'],
    'a switchGroup attribute' => ['is_featured'],
]);

it('answers 422 to a body that is not a CellUpdateRequest', function (array $body) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($a->id, $body)->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($a->fresh()->customer)->toBe('Ada');
})->with([
    'no column' => [['value' => 'Eve']],
    'a column that is not a string' => [['column' => ['customer'], 'value' => 'Eve']],
    'no value' => [['column' => 'customer']],
]);

it('answers 404 for an id outside the grid query', function (Closure $id) {
    [$a, $b, $c] = cellItems();
    cellRoutes();

    $response = patchCell($id($c), ['column' => 'customer', 'value' => 'Eve'])->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('message'))->toBe('找不到這筆資料，可能已經刪除，或不在這個列表裡。')
        ->and($c->fresh()->customer)->toBe('Cy');
})->with([
    'another shop\'s row' => [fn (CellItem $c) => $c->id],
    'a missing id' => [fn (CellItem $c) => $c->id + 1000],
]);

it('answers 422 for an id that cannot be a key', function (string $id) {
    [$a] = cellItems();
    cellRoutes();

    $response = patchCell($id, ['column' => 'customer', 'value' => 'Eve'])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and(array_keys($response->json('errors')))->toBe(['id'])
        ->and($a->fresh()->customer)->toBe('Ada');
})->with([
    'letters after digits' => ['1abc'],
    'a leading space' => ['%201'],
    'a sign' => ['-1'],
    'a decimal' => ['1.0'],
]);

it('reads an id with leading zeros as that number', function () {
    [$a] = cellItems();
    cellRoutes();

    patchCell('00'.$a->id, ['column' => 'customer', 'value' => 'Eve'])->assertOk()->assertJsonPath('row.id', $a->id);

    expect($a->fresh()->customer)->toBe('Eve');
});

it('answers 401 JSON when signed out', function () {
    [$a] = cellItems();
    cellRoutes();
    auth('brume')->logout();

    $response = patchCell($a->id, ['column' => 'customer', 'value' => 'Eve'])->assertUnauthorized();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($a->fresh()->customer)->toBe('Ada');
});

it('refuses inline editing of a relation column at declaration', function (Closure $declare) {
    $declare(new Column('customer.name', 'Customer'));
})->throws(InvalidArgumentException::class, '關聯欄位')->with([
    'editable' => [fn (Column $c) => $c->editable()],
    'switch' => [fn (Column $c) => $c->switch()],
    'switchGroup' => [fn (Column $c) => $c->switchGroup(['is_featured' => '精選'])],
]);

it('refuses a switchGroup over a relation attribute at declaration', function () {
    (new Column('flags', '標記'))->switchGroup(['is_featured' => '精選', 'profile.is_public' => '公開']);
})->throws(InvalidArgumentException::class, 'profile.is_public');

it('treats a blank value as null for every editor but text, even when the app keeps empty strings', function (string $column, mixed $value) {
    ConvertEmptyStringsToNull::skipWhen(fn () => true);
    [$a] = cellItems();
    cellRoutes();
    $before = CellItem::query()->whereKey($a->id)->first()->getAttributes();

    $response = patchCell($a->id, ['column' => $column, 'value' => $value])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and(array_keys($response->json('errors')))->toBe([$column])
        ->and(CellItem::query()->whereKey($a->id)->first()->getAttributes())->toBe($before);
})->with([
    'select, empty' => ['status', ''],
    'select, spaces' => ['status', '   '],
    'checkbox, empty' => ['tags', ''],
    'checkbox, an empty item' => ['tags', ['a', '']],
    'number, empty' => ['amount', ''],
    'switch, empty' => ['is_active', ''],
    'switchGroup, an empty attribute' => ['flags', ['is_featured' => '']],
])->after(fn () => ConvertEmptyStringsToNull::flushState());

it('saves a blank value as null when the column\'s rules say nullable', function () {
    ConvertEmptyStringsToNull::skipWhen(fn () => true);
    [$a] = cellItems();
    $a->update(['note' => 'x']);
    cellRoutes(fn (Grid $grid) => $grid->column('note', '備註')->editable(Editor::select(['x' => 'X']), 'nullable'));

    patchCell($a->id, ['column' => 'note', 'value' => ''])->assertOk();

    expect(CellItem::query()->whereKey($a->id)->value('note'))->toBeNull();
})->after(fn () => ConvertEmptyStringsToNull::flushState());

it('matches an option strictly on its string form and saves the declared option', function (string $value, ?string $saved) {
    [$a] = cellItems();
    cellRoutes(status: Editor::select([1 => '一', 2 => '二']));

    $response = patchCell($a->id, '{"column": "status", "value": '.$value.'}');

    if ($saved === null) {
        $response->assertUnprocessable()->assertJsonPath('errors.status', ['所選的 狀態 無效。']);
    } else {
        $response->assertOk();
    }

    expect(CellItem::query()->whereKey($a->id)->value('status'))->toBe($saved ?? 'open');
})->with([
    // Raw JSON values.
    'the option' => ['"1"', '1'],
    'an int' => ['1', '1'],
    'true' => ['true', null],
    'a float' => ['1.0', null],
    'leading zeros' => ['"01"', null],
    'a list' => ['["1"]', null],
]);

it('matches each checkbox item strictly and saves the declared options', function (string $value, ?array $saved) {
    [$a] = cellItems();
    cellRoutes(tags: Editor::checkbox([1 => '一', 2 => '二']));

    $response = patchCell($a->id, '{"column": "tags", "value": '.$value.'}');

    $saved === null ? $response->assertUnprocessable()->assertJsonValidationErrors(['tags']) : $response->assertOk();

    expect($a->fresh()->tags)->toBe($saved);
})->with([
    // Raw JSON values.
    'options and ints' => ['["1", 2]', ['1', '2']],
    'true' => ['[true]', null],
    'a float' => ['[1.0]', null],
    'leading zeros' => ['["01"]', null],
    'a nested list' => ['[["1"]]', null],
]);

it('refuses a fraction for a number on an integer-cast attribute or with a whole step', function (?Editor $customer, string $column, mixed $value, bool $ok) {
    [$a] = cellItems();
    cellRoutes(customer: $customer);

    $response = patchCell($a->id, ['column' => $column, 'value' => $value]);

    $ok ? $response->assertOk() : $response->assertUnprocessable()->assertJsonValidationErrors([$column]);
})->with([
    'int cast, a fraction' => [null, 'amount', 1.5, false],
    'int cast, a fraction string' => [null, 'amount', '1.5', false],
    'int cast, an exponent' => [null, 'amount', '1e2', false],
    'int cast, a whole number' => [null, 'amount', '15', true],
    'whole step, a fraction' => [Editor::number(step: 1), 'customer', '1.5', false],
    'fractional step, a fraction' => [Editor::number(step: 0.5), 'customer', '1.5', true],
]);

it('refuses a checkbox on an attribute without an array cast, before saving and on the list', function () {
    [$a] = cellItems();
    cellRoutes(status: Editor::checkbox(['open' => '未處理', 'paid' => '已付款']));
    $this->withoutExceptionHandling();

    expect(fn () => patchCell($a->id, ['column' => 'status', 'value' => ['paid']]))
        ->toThrow(LogicException::class, "'status' => 'array'")
        ->and($a->fresh()->status)->toBe('open')
        ->and(fn () => $this->get('/admin/cells'))->toThrow(LogicException::class, 'cast');
});

it('supports currency scales and strict dates and tags editors', function () {
    [$item] = cellItems();
    cellRoutes(function (Grid $grid) {
        $grid->column('price')->editable(Editor::currency(decimals: 2));
        $grid->column('due_date')->editable(Editor::date())->display(fn ($value) => 'Due '.$value);
    }, tags: Editor::tags());
    patchCell($item->id, ['column' => 'price', 'value' => '1280.5'])->assertOk();
    expect((string) $item->fresh()->price)->toBe('1280.50');
    patchCell($item->id, ['column' => 'price', 'value' => 'abc'])->assertUnprocessable();
    patchCell($item->id, ['column' => 'due_date', 'value' => '2026-02-30'])->assertUnprocessable();
    patchCell($item->id, ['column' => 'tags', 'value' => 'bad'])->assertUnprocessable();
    expect(Editor::currency()->toSchema()['decimals'])->toBe(0);
});

it('recomputes date display and canonical datetime raw values', function () {
    [$item] = cellItems();
    cellRoutes(function (Grid $grid) {
        $grid->column('due_date')->editable(Editor::date())->display(fn ($value) => 'Due '.$value);
        $grid->column('due_at')->editable(Editor::date(true))->display(fn ($value) => 'At '.$value);
        $grid->column('note')->editable(Editor::currency());
    }, tags: Editor::tags(['a' => 'A']));
    $date = patchCell($item->id, ['column' => 'due_date', 'value' => '2026-02-28'])->assertOk();
    expect($date->json('row.due_date'))->toBe('Due 2026-02-28');
    $datetime = patchCell($item->id, ['column' => 'due_at', 'value' => '2026-02-28T14:30'])->assertOk();
    expect($datetime->json('row._meta.raw.due_at'))->toBe('2026-02-28T14:30');
    patchCell($item->id, ['column' => 'due_at', 'value' => '2026-02-30T14:30'])->assertUnprocessable();
    patchCell($item->id, ['column' => 'note', 'value' => '1280.5'])->assertOk();
    expect($item->fresh()->note)->toBe('1281');
    patchCell($item->id, ['column' => 'tags', 'value' => ['custom']])->assertOk();
    expect($item->fresh()->tags)->toBe(['custom']);
    expect(JsonSchema::errors($datetime->json(), 'CellUpdateResponse'))->toBe([]);
});
