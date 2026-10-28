<?php

use Brume\Grid\Filters\FilterInput;
use Brume\Grid\Filters\Filters;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\FilterItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Order;

const FILTER_STATUSES = ['pending' => '待付款', 'paid' => '已付款', 'shipped' => '已出貨', 'cancelled' => '已取消'];

/**
 * Four orders whose ids follow this list.
 */
function filterOrders(): void
{
    foreach ([
        ['BR-001', 'Ada Lovelace', 100, 'paid', '服飾', '2026-01-05 10:00:00'],
        ['BR-002', 'ada king', 250, 'pending', '家居', '2026-02-14 09:30:00'],
        ['BR-003', 'Grace Hopper', 500, 'shipped', '美妝', '2026-02-28 23:00:00'],
        ['BR-004', 'Linus', 800, 'cancelled', '食品', '2025-12-31 18:00:00'],
    ] as [$number, $customer, $amount, $status, $category, $at]) {
        Order::factory()->create([
            'number' => $number, 'customer' => $customer, 'amount' => $amount, 'status' => $status,
            'category' => $category, 'created_at' => Carbon::parse($at),
        ]);
    }
}

/**
 * Which of the four orders a page lists, by their place in filterOrders() (1–4), whatever ids the
 * database gave them.
 *
 * @return list<int>
 */
function filterIds(array $page): array
{
    return array_map(fn (array $row) => (int) substr($row['number'], 3), $page['rows']);
}

function filterGrid(?Closure $declare = null): Grid
{
    $grid = Grid::make(Order::class)->title('Orders');
    $grid->column('number');
    $grid->column('customer');
    $grid->column('amount')->numeric();
    $grid->column('status');

    if ($declare) {
        $declare($grid->filters(), $grid);
    }

    return $grid;
}

function filterPage(Grid $grid, array $query = []): array
{
    // HandleBrumeRequests sets the locale per request; these call toSchema() directly.
    app()->setLocale('zh_TW');

    return $grid->toSchema(Request::create('/admin/orders', 'GET', $query));
}

beforeEach(function () {
    filterOrders();
});

it('filters by each condition', function (Closure $declare, array $query, array $ids) {
    $page = filterPage(filterGrid($declare), $query);

    expect(filterIds($page))->toBe($ids);
})->with([
    'equal' => [fn (Filters $f) => $f->equal('status', '狀態'), ['filter' => ['status' => 'paid']], [1]],
    'notEqual' => [fn (Filters $f) => $f->notEqual('status', '狀態'), ['filter' => ['status' => 'paid']], [2, 3, 4]],
    'like' => [fn (Filters $f) => $f->like('customer', '客戶'), ['filter' => ['customer' => 'Lovelace']], [1]],
    'ilike' => [fn (Filters $f) => $f->ilike('customer', '客戶'), ['filter' => ['customer' => 'ADA']], [1, 2]],
    'startWith' => [fn (Filters $f) => $f->startWith('customer', '客戶'), ['filter' => ['customer' => 'Gra']], [3]],
    'endWith' => [fn (Filters $f) => $f->endWith('customer', '客戶'), ['filter' => ['customer' => 'king']], [2]],
    'gt' => [fn (Filters $f) => $f->gt('amount', '金額'), ['filter' => ['amount' => '250']], [3, 4]],
    'lt' => [fn (Filters $f) => $f->lt('amount', '金額'), ['filter' => ['amount' => '250']], [1]],
    'ngt' => [fn (Filters $f) => $f->ngt('amount', '金額'), ['filter' => ['amount' => '250']], [1, 2]],
    'nlt' => [fn (Filters $f) => $f->nlt('amount', '金額'), ['filter' => ['amount' => '250']], [2, 3, 4]],
    'in' => [fn (Filters $f) => $f->in('status', '狀態')->multipleSelect(FILTER_STATUSES), ['filter' => ['status' => ['paid', 'shipped']]], [1, 3]],
    'notIn' => [fn (Filters $f) => $f->notIn('status', '狀態')->checkbox(FILTER_STATUSES), ['filter' => ['status' => ['paid', 'shipped']]], [2, 4]],
    'findInSet' => [fn (Filters $f) => $f->findInSet('status', '狀態'), ['filter' => ['status' => 'paid']], [1]],
    'date' => [fn (Filters $f) => $f->date('created', '建立日', 'created_at'), ['filter' => ['created' => '2026-02-14']], [2]],
    'day' => [fn (Filters $f) => $f->day('created', '日', 'created_at'), ['filter' => ['created' => '5']], [1]],
    'month' => [fn (Filters $f) => $f->month('created', '月', 'created_at'), ['filter' => ['created' => '2']], [2, 3]],
    'year' => [fn (Filters $f) => $f->year('created', '年', 'created_at'), ['filter' => ['created' => '2025']], [4]],
    'between' => [fn (Filters $f) => $f->between('amount', '金額')->number(), ['filter' => ['amount' => ['start' => '200', 'end' => '600']]], [2, 3]],
    'between dates' => [fn (Filters $f) => $f->between('created', '建立日', 'created_at')->date(), ['filter' => ['created' => ['start' => '2026-02-01', 'end' => '2026-02-28']]], [2, 3]],
    'whereBetween' => [
        fn (Filters $f) => $f->whereBetween('amount', '金額', fn (Builder $q, ?string $start, ?string $end) => $q->where('amount', '>', $start)->where('amount', '<', $end ?? PHP_INT_MAX)),
        ['filter' => ['amount' => ['start' => '100']]], [2, 3, 4],
    ],
    'where' => [
        // The orWhere stays inside the filter's own nested group.
        fn (Filters $f) => $f->scope('cheap', '便宜', fn (Builder $q) => $q->where('amount', '<', 300))
            ->where('status', '狀態', fn (Builder $q, string $value) => $q->where('status', $value)->orWhere('amount', '>', 700)),
        ['filter' => ['status' => 'paid'], 'scope' => 'cheap'], [1],
    ],
    'group' => [fn (Filters $f) => $f->group('amount', '金額')->number(), ['filter' => ['amount' => ['op' => 'nlt', 'value' => '500']]], [3, 4]],
    'hidden' => [fn (Filters $f) => $f->hidden('status'), ['filter' => ['status' => 'shipped']], [3]],
    'scope' => [fn (Filters $f) => $f->scope('big', '大單', fn (Builder $q) => $q->where('amount', '>=', 500)), ['scope' => 'big'], [3, 4]],
    'selector' => [fn (Filters $f) => $f->selector('status', '狀態', FILTER_STATUSES, multiple: true), ['filter' => ['status' => ['pending', 'cancelled']]], [2, 4]],
    'newline' => [fn (Filters $f) => $f->equal('status', '狀態')->select(FILTER_STATUSES) && $f->newline()->gt('amount', '金額'), ['filter' => ['status' => 'paid', 'amount' => '50']], [1]],
    'columns' => [fn (Filters $f) => $f->columns(2)->lt('amount', '金額'), ['filter' => ['amount' => '300']], [1, 2]],
]);

it('filters through a relation and a JSON path', function () {
    $red = FilterItem::query()->create(['order_id' => Order::query()->where('number', 'BR-001')->value('id'), 'meta' => ['color' => 'red']]);
    $blue = FilterItem::query()->create(['order_id' => Order::query()->where('number', 'BR-003')->value('id'), 'meta' => ['color' => 'blue']]);

    $items = Grid::make(FilterItem::class);
    $items->column('id');
    $items->filters()->equal('order_status', '訂單狀態', 'order.status');
    $items->filters()->equal('color', '顏色', 'meta->color');

    expect(array_column(filterPage($items, ['filter' => ['order_status' => 'shipped']])['rows'], 'id'))->toBe([$blue->id])
        ->and(array_column(filterPage($items, ['filter' => ['color' => 'red']])['rows'], 'id'))->toBe([$red->id]);
});

it('keeps hidden filters out of the panel', function () {
    $page = filterPage(filterGrid(fn (Filters $f) => $f->equal('status', '狀態') && $f->hidden('customer')), ['filter' => ['customer' => 'Linus']]);

    expect(array_column($page['filters']['items'], 'key'))->toBe(['status'])
        ->and($page)->not->toHaveKey('columnFilters')
        ->and($page['appliedFilters'])->toBe([['key' => 'customer', 'label' => 'Customer', 'display' => 'Linus']]);
});

it('outputs the panel, column filters, selectors and scopes', function () {
    $grid = filterGrid(function (Filters $f, Grid $grid) {
        $f->columns(2);
        $f->like('number', '訂單編號')->text('BR-…');
        $f->in('status', '狀態')->multipleSelect(FILTER_STATUSES)->span(2);
        $f->newline();
        $f->between('created', '建立時間', 'created_at')->date(withTime: true);
        $f->group('amount_cond', '金額條件', ['gt', 'lt'], 'amount')->number();
        $f->year('year', '年份', 'created_at');
        $f->day('day', '日', 'created_at')->radio(['1' => '1 日', '15' => '15 日']);
        $f->ilike('customer_name', '客戶', 'customer')->remoteSelect(User::class, 'name', 'username');
        $f->hidden('ref', 'number');
        $f->selector('category_pick', '分類', ['服飾' => '服飾', '家居' => '家居']);
        $f->scope('big', '大單', fn (Builder $q) => $q->where('amount', '>=', 500));
        $f->scope('open', '未完成', fn (Builder $q) => $q->whereIn('status', ['pending', 'paid']));
        $grid->column('category')->filter('in', ['服飾' => '服飾', '家居' => '家居']);
    });
    $grid->column('created_at')->filter('between');

    $page = filterPage($grid);

    expect($page['filters'])->toBe([
        'columns' => 2,
        'items' => [
            ['key' => 'number', 'label' => '訂單編號', 'input' => ['type' => 'text', 'placeholder' => 'BR-…'], 'placement' => 'panel'],
            ['key' => 'status', 'label' => '狀態', 'input' => ['type' => 'multipleSelect', 'options' => [
                ['value' => 'pending', 'label' => '待付款'], ['value' => 'paid', 'label' => '已付款'],
                ['value' => 'shipped', 'label' => '已出貨'], ['value' => 'cancelled', 'label' => '已取消'],
            ]], 'placement' => 'panel', 'span' => 2],
            ['type' => 'newline'],
            ['key' => 'created', 'label' => '建立時間', 'input' => ['type' => 'range', 'of' => 'datetime'], 'placement' => 'panel'],
            ['key' => 'amount_cond', 'label' => '金額條件', 'input' => ['type' => 'group', 'of' => 'number', 'operators' => [
                ['value' => 'gt', 'label' => '>'], ['value' => 'lt', 'label' => '<'],
            ]], 'placement' => 'panel'],
            ['key' => 'year', 'label' => '年份', 'input' => ['type' => 'text', 'inputMode' => 'numeric'], 'placement' => 'panel'],
            ['key' => 'day', 'label' => '日', 'input' => ['type' => 'radio', 'options' => [
                ['value' => '1', 'label' => '1 日'], ['value' => '15', 'label' => '15 日'],
            ]], 'placement' => 'panel'],
            ['key' => 'customer_name', 'label' => '客戶', 'input' => ['type' => 'select', 'remote' => true], 'placement' => 'panel'],
        ],
    ])
        ->and($page['columnFilters'])->toBe([
            ['key' => 'category', 'label' => 'Category', 'input' => ['type' => 'multipleSelect', 'options' => [
                ['value' => '服飾', 'label' => '服飾'], ['value' => '家居', 'label' => '家居'],
            ]], 'placement' => 'column'],
            ['key' => 'created_at', 'label' => 'Created At', 'input' => ['type' => 'range', 'of' => 'text'], 'placement' => 'column'],
        ])
        ->and($page['selectors'])->toBe([
            ['key' => 'category_pick', 'label' => '分類', 'multiple' => false, 'options' => [
                ['value' => '服飾', 'label' => '服飾'], ['value' => '家居', 'label' => '家居'],
            ]],
        ])
        ->and($page['scopes'])->toBe([['key' => 'big', 'label' => '大單'], ['key' => 'open', 'label' => '未完成']])
        ->and(collect($page['columns'])->pluck('filter', 'key')->filter()->all())->toBe(['category' => 'category', 'created_at' => 'created_at']);
});

it('uses the default input of each condition', function () {
    $grid = filterGrid(function (Filters $f) {
        $f->day('day', '日', 'created_at');
        $f->month('month', '月', 'created_at');
        $f->date('date', '日期', 'created_at');
        $f->between('amount', '金額');
        $f->group('cond', '條件', column: 'amount');
        $f->equal('status', '狀態');
    });

    $inputs = collect(filterPage($grid)['filters']['items'])->pluck('input', 'key');

    expect($inputs['day']['type'])->toBe('select')
        ->and(array_column($inputs['day']['options'], 'value'))->toBe(array_map('strval', range(1, 31)))
        ->and(array_column($inputs['month']['options'], 'value'))->toBe(array_map('strval', range(1, 12)))
        ->and($inputs['date'])->toBe(['type' => 'date', 'withTime' => false])
        ->and($inputs['amount'])->toBe(['type' => 'range', 'of' => 'text'])
        ->and(array_column($inputs['cond']['operators'], 'value'))->toBe(['equal', 'gt', 'lt', 'ngt', 'nlt'])
        ->and($inputs['status'])->toBe(['type' => 'text']);
});

it('makes a numeric column\'s header filter take numbers', function () {
    $grid = filterGrid();
    $grid->column('total')->filter('gt')->numeric();
    $grid->column('price')->numeric()->filter('between');

    expect(collect(filterPage($grid)['columnFilters'])->pluck('input', 'key')->all())->toBe([
        'total' => ['type' => 'text', 'inputMode' => 'numeric'],
        'price' => ['type' => 'range', 'of' => 'number'],
    ]);
});

it('requires options for in and notIn', function (string $condition) {
    filterPage(filterGrid(fn (Filters $f) => $f->{$condition}('status', '狀態')));
})->with(['in', 'notIn'])->throws(InvalidArgumentException::class, '需要選項');

it('echoes the applied values and labels each one', function () {
    $grid = filterGrid(function (Filters $f) {
        $f->scope('big', '大單', fn (Builder $q) => $q->where('amount', '>=', 0));
        $f->selector('pick', '狀態', FILTER_STATUSES, column: 'status');
        $f->equal('status_one', '狀態（單選）', 'status')->select(FILTER_STATUSES);
        $f->in('status_many', '狀態（多選）', 'status')->checkbox(FILTER_STATUSES);
        $f->between('amount', '金額')->number();
        $f->between('amount_from', '金額起', 'amount')->number();
        $f->between('amount_to', '金額迄', 'amount')->number();
        $f->between('created', '建立時間', 'created_at')->date(withTime: true);
        $f->group('cond', '金額條件', column: 'amount')->number();
        $f->like('customer', '客戶');
        $f->equal('owner', '負責人', 'customer')->remoteSelect(User::class, 'name', 'username');
    });
    brumeUser(['username' => 'linus', 'name' => 'Linus T.']);

    $page = filterPage($grid, [
        'q' => 'ignored without quick search',
        'scope' => 'big',
        'filter' => [
            'pick' => 'paid',
            'status_one' => 'paid',
            'status_many' => ['paid', 'shipped'],
            'amount' => ['start' => '100', 'end' => '500'],
            'amount_from' => ['start' => '100'],
            'amount_to' => ['end' => '500'],
            'created' => ['start' => '2026-01-01T08:00'],
            'cond' => ['op' => 'gt', 'value' => '100'],
            'customer' => 'Lin',
            'owner' => 'linus',
        ],
    ]);

    expect(array_map(fn ($a) => "{$a['label']}: {$a['display']}", $page['appliedFilters']))->toBe([
        '範圍: 大單',
        '狀態: 已付款',
        '狀態（單選）: 已付款',
        '狀態（多選）: 已付款、已出貨',
        '金額: 100 – 500',
        '金額起: ≥ 100',
        '金額迄: ≤ 500',
        '建立時間: ≥ 2026-01-01 08:00',
        '金額條件: > 100',
        '客戶: Lin',
        '負責人: Linus T.',
    ])
        ->and($page['appliedFilters'][0]['key'])->toBe('scope')
        ->and($page['query']['scope'])->toBe('big')
        ->and($page['query']['filters'])->toEqual((object) [
            'pick' => 'paid',
            'status_one' => 'paid',
            'status_many' => ['paid', 'shipped'],
            'amount' => ['start' => '100', 'end' => '500'],
            'amount_from' => ['start' => '100'],
            'amount_to' => ['end' => '500'],
            'created' => ['start' => '2026-01-01T08:00'],
            'cond' => ['op' => 'gt', 'value' => '100'],
            'customer' => 'Lin',
            'owner' => 'linus',
        ])
        ->and(filterIds($page))->toBe([]);
});

it('labels a remote filter with one query for all its values', function () {
    brumeUser(['username' => 'ada', 'name' => 'Ada L.']);
    brumeUser(['username' => 'grace', 'name' => 'Grace H.']);
    $grid = filterGrid(fn (Filters $f) => $f->in('owner', '負責人', 'customer')->remoteMultipleSelect(User::class, 'name', 'username'));

    DB::enableQueryLog();
    $page = filterPage($grid, ['filter' => ['owner' => ['grace', 'ada', 'gone']]]);
    $userQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'brume_users'));

    expect($page['appliedFilters'][0]['display'])->toBe('Grace H.、Ada L.、gone')
        ->and($userQueries)->toHaveCount(1);
});

it('applies defaults only while the URL has no filter parameter', function (array $query, array $ids, array $echoed) {
    $grid = filterGrid(function (Filters $f) {
        $f->equal('status', '狀態')->select(FILTER_STATUSES)->default('shipped');
        $f->between('amount', '金額')->number()->default(['start' => '400']);
        $f->like('customer', '客戶');
    });

    $page = filterPage($grid, $query);

    expect(filterIds($page))->toBe($ids)
        ->and($page['query']['filters'])->toEqual((object) $echoed)
        ->and(array_column($page['appliedFilters'], 'key'))->toBe(array_keys($echoed));
})->with([
    'no filter parameter' => [[], [3], ['status' => 'shipped', 'amount' => ['start' => '400']]],
    'another filter' => [['filter' => ['customer' => 'a']], [1, 2, 3], ['customer' => 'a']],
    'the filter itself, empty' => [['filter' => ['status' => '']], [1, 2, 3, 4], []],
    'the filter itself, set' => [['filter' => ['status' => 'paid']], [1], ['status' => 'paid']],
    'a filter the grid does not have' => [['filter' => ['nope' => 'x']], [1, 2, 3, 4], []],
]);

it('reads ?filter[status]= from the URL as defaults off', function () {
    $grid = filterGrid(fn (Filters $f) => $f->equal('status', '狀態')->default('shipped'));
    app()->setLocale('zh_TW');

    $page = $grid->toSchema(Request::create('/admin/orders?filter[status]='));

    expect(filterIds($page))->toBe([1, 2, 3, 4])
        ->and($page['appliedFilters'])->toBe([]);
});

it('ignores a list filter with more than 100 values', function () {
    $grid = filterGrid(fn (Filters $f) => $f->in('status', '狀態')->multipleSelect(FILTER_STATUSES));
    $many = array_merge(['paid'], array_fill(0, FilterInput::MAX_LIST, 'shipped'));

    expect(filterIds(filterPage($grid, ['filter' => ['status' => $many]])))->toBe([1, 2, 3, 4])
        ->and(filterIds(filterPage($grid, ['filter' => ['status' => array_slice($many, 0, FilterInput::MAX_LIST)]])))->toBe([1, 3]);
});

it('takes only a four-digit year', function (string $year, array $ids) {
    $grid = filterGrid(fn (Filters $f) => $f->year('year', '年', 'created_at'));

    expect(filterIds(filterPage($grid, ['filter' => ['year' => $year]])))->toBe($ids);
})->with([
    'a year' => ['2025', [4]],
    'exponent' => ['2e3', [1, 2, 3, 4]],
    'negative' => ['-2025', [1, 2, 3, 4]],
    'decimal' => ['2025.0', [1, 2, 3, 4]],
    'five digits' => ['20250', [1, 2, 3, 4]],
]);

it('ignores filter values of the wrong shape and answers 200', function (array $query) {
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->get('/grid-filters', fn () => filterGrid(function (Filters $f) {
        $f->between('amount', '金額')->number();
        $f->equal('status', '狀態')->select(FILTER_STATUSES);
        $f->in('statuses', '狀態們', 'status')->multipleSelect(FILTER_STATUSES);
        $f->group('cond', '條件', column: 'amount')->number();
        $f->date('created', '建立日', 'created_at');
        $f->month('month', '月', 'created_at');
        $f->findInSet('set', '集合', 'status');
        $f->scope('big', '大單', fn (Builder $q) => $q->where('amount', '>=', 500));
    }));

    $envelope = $this->get('/grid-filters?'.http_build_query($query))->assertOk()
        ->viewData('page')['props']['envelope'];

    expect(filterIds($envelope['page']))->toBe([1, 2, 3, 4])
        ->and($envelope['page']['appliedFilters'])->toBe([])
        ->and(json_encode($envelope['page']['query']['filters']))->toBe('{}')
        ->and(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
})->with([
    'a scalar on between' => [['filter' => ['amount' => 'abc']]],
    'nested too deep' => [['filter' => ['status' => ['x' => ['y' => '1']]]]],
    'a list on a single select' => [['filter' => ['status' => ['paid']]]],
    'a value outside the options' => [['filter' => ['status' => 'refunded', 'statuses' => ['refunded'], 'month' => '13']]],
    'not a number in a number range' => [['filter' => ['amount' => ['start' => 'abc', 'end' => ['x']]]]],
    'an unknown operator' => [['filter' => ['cond' => ['op' => 'drop', 'value' => '1']]]],
    'a group without a value' => [['filter' => ['cond' => ['op' => 'gt', 'value' => '']]]],
    'not a date' => [['filter' => ['created' => '2026-02-30']]],
    'a set item with a comma' => [['filter' => ['set' => 'paid,shipped']]],
    'an unknown filter and scope' => [['filter' => ['nope' => 'x'], 'scope' => 'nope']],
    'filter is not an array' => [['filter' => 'status']],
    'empty values' => [['filter' => ['status' => '', 'statuses' => [''], 'amount' => ['start' => '', 'end' => '']]]],
]);

it('rejects a duplicate key', function (Closure $declare) {
    filterGrid($declare);
})->with([
    'two filters' => [fn (Filters $f) => $f->equal('status', 'A') && $f->like('status', 'B')],
    'a filter and a selector' => [fn (Filters $f) => $f->equal('status', 'A') && $f->selector('status', 'B', ['a' => 'A'])],
    'a filter and a column filter' => [fn (Filters $f, Grid $g) => $f->equal('status', 'A') && $g->column('status')->filter('equal')],
    'a hidden filter' => [fn (Filters $f) => $f->hidden('status') && $f->hidden('status')],
    'two scopes' => [fn (Filters $f) => $f->scope('big', 'A', fn () => null)->scope('big', 'B', fn () => null)],
])->throws(InvalidArgumentException::class, '重複');

it('rejects invalid declarations', function (Closure $declare, string $message) {
    expect(fn () => filterGrid($declare))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'columns out of range' => [fn (Filters $f) => $f->columns(5), 'columns()'],
    'span out of range' => [fn (Filters $f) => $f->equal('a', 'A')->span(3), 'span()'],
    'a select on between' => [fn (Filters $f) => $f->between('a', 'A')->select(['x' => 'X']), 'select()'],
    'a list input on like' => [fn (Filters $f) => $f->like('a', 'A')->checkbox(['x' => 'X']), 'checkbox()'],
    'number on a select' => [fn (Filters $f) => $f->equal('a', 'A')->select(['x' => 'X'])->number(), 'number()'],
    'json on equal' => [fn (Filters $f) => $f->equal('a', 'A')->json(), 'json()'],
    'an unknown group operator' => [fn (Filters $f) => $f->group('a', 'A', ['gt', 'between']), 'between'],
    'an unknown column filter' => [fn (Filters $f, Grid $g) => $g->column('a')->filter('regex'), 'regex'],
    'a key with brackets' => [fn (Filters $f) => $f->equal('a[b]', 'A'), 'a[b]'],
    'a date group' => [fn (Filters $f) => $f->group('a', 'A')->date(), 'group 篩選只支援 text 或 number；日期請用 between()->date()'],
    'a relation search column' => [fn (Filters $f) => $f->equal('a', 'A')->remoteSelect(User::class, 'name', searchColumns: ['name', 'roles.name']), 'roles.name'],
]);

it('outputs column.filter for a header filter', function () {
    $grid = filterGrid();
    $grid->column('category')->filter('in', options: ['服飾' => '服飾', '家居' => '家居']);

    $page = filterPage($grid, ['filter' => ['category' => ['家居']]]);
    $column = collect($page['columns'])->firstWhere('key', 'category');

    expect($column['filter'])->toBe('category')
        ->and($page['columnFilters'][0]['key'])->toBe('category')
        ->and($page['columnFilters'][0]['placement'])->toBe('column')
        ->and(filterIds($page))->toBe([2])
        ->and($page)->not->toHaveKey('filters');
});

it('maps each header filter type to a condition', function (string $column, string $type, array $options, mixed $value, array $ids) {
    $grid = Grid::make(Order::class);
    $grid->column('number');
    $grid->column($column)->filter($type, $options);

    expect(filterIds(filterPage($grid, ['filter' => [$column => $value]])))->toBe($ids);
})->with([
    'between' => ['amount', 'between', [], ['start' => '200', 'end' => '500'], [2, 3]],
    'checkbox' => ['category', 'checkbox', ['服飾' => '服飾', '美妝' => '美妝'], ['服飾', '美妝'], [1, 3]],
    'equal' => ['category', 'equal', [], '美妝', [3]],
    'equal with options' => ['category', 'equal', ['美妝' => '美妝'], '美妝', [3]],
    'in' => ['category', 'in', ['服飾' => '服飾', '食品' => '食品'], ['食品'], [4]],
    'input' => ['category', 'input', [], '居', [2]],
    'like' => ['category', 'like', [], '妝', [3]],
    'startWith' => ['category', 'startWith', [], '食', [4]],
    'gt' => ['amount', 'gt', [], '250', [3, 4]],
    'lt' => ['amount', 'lt', [], '250', [1]],
    'ngt' => ['amount', 'ngt', [], '250', [1, 2]],
    'nlt' => ['amount', 'nlt', [], '500', [3, 4]],
]);

it('validates a page with every kind of filter applied against PageEnvelope', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])
        ->group(fn () => Route::brumeGrid('orders', fn () => filterGrid(function (Filters $f, Grid $grid) {
            $f->columns(4);
            $f->equal('status', '狀態')->select(FILTER_STATUSES)->span(2);
            $f->newline();
            $f->between('amount', '金額')->number();
            $f->group('cond', '條件', column: 'amount');
            $f->date('created', '建立日', 'created_at')->date(withTime: true);
            $f->equal('owner', '負責人', 'customer')->remoteSelect(User::class, 'name', 'username');
            $f->hidden('number');
            $f->selector('pick', '狀態', FILTER_STATUSES, multiple: true, column: 'status');
            $f->scope('big', '大單', fn (Builder $q) => $q->where('amount', '>=', 500));
            $grid->column('category')->filter('in', ['服飾' => '服飾']);
        })));

    $envelope = $this->get('/admin/orders?'.http_build_query([
        'scope' => 'big',
        'filter' => [
            'status' => 'shipped', 'amount' => ['start' => '1'], 'cond' => ['op' => 'gt', 'value' => '1'],
            'created' => '2026-02-28T22:00', 'owner' => 'x', 'number' => 'BR-003', 'pick' => ['shipped'], 'category' => ['服飾'],
        ],
    ]))->assertOk()->viewData('page')['props']['envelope'];

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([])
        ->and($envelope['page']['appliedFilters'])->toHaveCount(9);
});
