<?php

use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Workbench\App\Models\Order;

/**
 * /admin/orders: the orders outside 食品, on a Route::brumeGrid page behind brume.auth as a
 * project's routes/brume.php is. $declare configures the grid (exportable() included).
 */
function exportRoutes(?Closure $declare = null): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('orders', function () use ($declare) {
            $grid = Grid::make(Order::query()->where('category', '!=', '食品'))->title('訂單')->perPage(10);
            $grid->column('number', '訂單編號')->sortable();
            $grid->column('status', '狀態')->label(Order::STATUS_LABELS);
            $grid->column('amount', '金額')->numeric();
            $grid->filters()->equal('status', '狀態')->select(Order::STATUS_LABELS);

            if ($declare) {
                $declare($grid);
            } else {
                $grid->exportable();
            }

            return $grid;
        }));
}

/**
 * Orders BR0001 .. BR{$count}: status paid on odd numbers, pending on even ones, amount 10 × n.
 *
 * @param  array<string, mixed>  $attributes  for every order
 */
function exportOrders(int $count, array $attributes = []): void
{
    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        Order::query()->insert(array_map(fn (int $n) => [
            'number' => sprintf('BR%04d', $n),
            'customer' => "客戶 {$n}",
            'amount' => $n * 10,
            'status' => $n % 2 === 1 ? 'paid' : 'pending',
            'category' => '服飾',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ], $chunk));
    }
}

/**
 * The id of order BR{$n}.
 */
function exportId(int $n): int
{
    return Order::query()->where('number', sprintf('BR%04d', $n))->value('id');
}

/**
 * The sheet's rows as values, read back with openspout.
 *
 * @return list<list<mixed>>
 */
function exportSheet(TestResponse $response): array
{
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'brume-export-');
    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * The XML of the first worksheet in the response.
 */
function exportSheetXml(TestResponse $response): string
{
    $path = tempnam(sys_get_temp_dir(), 'brume-export-');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    return $xml;
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
});

it('exports every row matching the filters, in the list order', function () {
    exportOrders(25);
    exportOrders(1, ['number' => 'FOOD', 'category' => '食品', 'status' => 'paid']);
    exportRoutes();

    $rows = exportSheet($this->get('/admin/orders/_brume/export?export_scope=all&filter[status]=paid&sort=-number'));

    expect($rows[0])->toBe(['訂單編號', '狀態', '金額'])
        ->and(count($rows))->toBe(1 + 13)
        ->and($rows[1])->toBe(['BR0025', '已付款', 250])
        ->and(array_column(array_slice($rows, 1), 0))->toBe(array_map(fn (int $n) => sprintf('BR%04d', $n), range(25, 1, -2)));
});

it('exports every matching row for an unknown or missing scope', function (string $query) {
    exportOrders(12);
    exportRoutes();

    expect(count(exportSheet($this->get('/admin/orders/_brume/export'.$query))))->toBe(13);
})->with(['missing' => [''], 'unknown' => ['?export_scope=everything'], 'array' => ['?export_scope[]=page']]);

it('applies the quick search', function () {
    exportOrders(12);
    exportRoutes(function (Grid $grid) {
        $grid->quickSearch('number');
        $grid->exportable();
    });

    expect(array_column(exportSheet($this->get('/admin/orders/_brume/export?q=br001')), 0))
        ->toBe(['訂單編號', 'BR0010', 'BR0011', 'BR0012']);
});

it('exports only the current page', function () {
    exportOrders(25);
    exportRoutes();

    $rows = exportSheet($this->get('/admin/orders/_brume/export?export_scope=page&page=2&per_page=10'));

    expect(array_column(array_slice($rows, 1), 0))->toBe(array_map(fn (int $n) => sprintf('BR%04d', $n), range(11, 20)))
        // Past the end: the last page, as the list shows it.
        ->and(array_column(array_slice(exportSheet($this->get('/admin/orders/_brume/export?export_scope=page&page=9')), 1), 0))
        ->toBe(array_map(fn (int $n) => sprintf('BR%04d', $n), range(21, 25)));
});

it('exports only the selected rows the grid can show', function () {
    exportOrders(5);
    exportOrders(1, ['number' => 'FOOD', 'category' => '食品']);
    exportRoutes();
    $food = Order::query()->where('number', 'FOOD')->value('id');

    $query = 'export_scope=selected&ids[]='.exportId(4).'&ids[]='.exportId(2).'&ids[]='.$food.'&ids[]=999999&ids[]=1abc&ids[]=0'.exportId(2);
    $rows = exportSheet($this->get('/admin/orders/_brume/export?'.$query));

    expect($rows)->toBe([['訂單編號', '狀態', '金額'], ['BR0002', '待付款', 20], ['BR0004', '待付款', 40]])
        ->and(exportSheet($this->get('/admin/orders/_brume/export?export_scope=selected')))->toBe([['訂單編號', '狀態', '金額']])
        ->and(exportSheet($this->get('/admin/orders/_brume/export?export_scope=selected&ids='.exportId(1))))->toBe([['訂單編號', '狀態', '金額']]);
});

it('refuses more selected ids than the largest page', function () {
    exportRoutes();
    $ids = implode('&', array_map(fn (int $id) => "ids[]={$id}", range(1, 101)));

    $this->getJson('/admin/orders/_brume/export?export_scope=selected&'.$ids)
        ->assertStatus(422)
        ->assertJson(['message' => '一次最多只能匯出 100 筆。']);

    expect(exportSheet($this->get('/admin/orders/_brume/export?export_scope=selected&'.implode('&', array_map(fn (int $id) => "ids[]={$id}", range(1, 100))))))
        ->toBe([['訂單編號', '狀態', '金額']]);
});

it('writes the columns that are exportable, with exportUsing, display closures and label options', function () {
    exportOrders(1, ['status' => 'cancelled', 'category' => '家居']);
    exportRoutes(function (Grid $grid) {
        $grid->column('customer', '客戶')->exportUsing(fn ($value, Order $row) => "{$value}（{$row->number}）");
        $grid->column('category', '分類')->exportable(false);
        $grid->column('tags', '標籤')->display(fn ($value, Order $row) => [$row->status, $row->category])->label(Order::STATUS_LABELS);
        $grid->column('note', '備註')->display(fn ($value, Order $row) => "金額 {$row->amount}");
        $grid->column('badge', '元件')->display(fn () => 7)->component('OrderBadge');
        $grid->column('none', '空白');
        $grid->exportable();
    });

    expect(exportSheet($this->get('/admin/orders/_brume/export')))->toBe([
        ['訂單編號', '狀態', '金額', '客戶', '標籤', '備註', '元件', '空白'],
        ['BR0001', '已取消', 10, '客戶 1（BR0001）', '已取消、家居', '金額 10', 7, ''],
    ]);
});

it('writes text that looks like a formula as plain text', function (string $text) {
    exportOrders(1, ['customer' => $text]);
    exportRoutes(function (Grid $grid) {
        $grid->column('customer', '客戶');
        $grid->column('formula', '公式')->exportUsing(fn () => '=1+1');
        $grid->exportable();
    });

    $response = $this->get('/admin/orders/_brume/export');

    // A raw CR in the XML reads back as LF (XML line-end handling), still as text.
    expect(exportSheet($response)[1][3])->toBe(str_replace("\r", "\n", $text))
        ->and(exportSheet($response)[1][4])->toBe('=1+1')
        ->and(exportSheetXml($response))->not->toContain('<f>')->not->toContain('<f ');
})->with([
    'equals' => ['=HYPERLINK("http://evil.example","x")'],
    'plus' => ['+1+1'],
    'minus' => ['-2+3'],
    'at' => ['@SUM(A1)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
]);

it('answers with an xlsx download named after the title', function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(9, 5, 7));
    exportRoutes();

    $response = $this->get('/admin/orders/_brume/export')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain("filename*=UTF-8''".rawurlencode('訂單-2026-10-29-090507.xlsx'))
        ->toStartWith('attachment; filename="');
});

it('uses the filename exportable() gives', function () {
    exportRoutes(fn (Grid $grid) => $grid->exportable('出貨單/十月'));

    expect($this->get('/admin/orders/_brume/export')->headers->get('Content-Disposition'))
        ->toContain("filename*=UTF-8''".rawurlencode('出貨單_十月.xlsx'));
});

it('answers a JSON 404 without exportable()', function () {
    exportRoutes(fn (Grid $grid) => null);

    $this->getJson('/admin/orders/_brume/export')->assertNotFound()->assertJson(['message' => '這個列表沒有提供這項功能。']);
});

it('sends page.export.url once exportable() is declared', function () {
    exportRoutes();

    $envelope = $this->get('/admin/orders')->assertOk()->viewData('page')['props']['envelope'];

    expect($envelope['page']['export'])->toBe(['url' => '/admin/orders/_brume/export'])
        ->and(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([]);
});

it('leaves page.export out without exportable()', function () {
    exportRoutes(fn (Grid $grid) => null);

    expect($this->get('/admin/orders')->viewData('page')['props']['envelope']['page'])->not->toHaveKey('export');
});

it('requires Route::brumeGrid for an export in local', function () {
    app()->detectEnvironment(fn () => 'local');

    Grid::make(Order::class)->title('訂單')->exportable()->toSchema(Request::create('/admin/orders'));
})->throws(LogicException::class, 'Route::brumeGrid');

it('streams 1,200 rows within a small memory budget', function () {
    exportOrders(1200);
    exportRoutes();
    gc_collect_cycles();
    $before = memory_get_peak_usage();

    $response = $this->get('/admin/orders/_brume/export');
    $content = $response->streamedContent();

    expect(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024)
        ->and(strlen($content))->toBeGreaterThan(0)
        ->and(count(exportSheet($response)))->toBe(1201);
});

it('refuses a cell value that is not a scalar, pointing at exportUsing()', function () {
    $this->withoutExceptionHandling();
    exportOrders(1);
    exportRoutes(function (Grid $grid) {
        $grid->column('self', '本身')->exportUsing(fn ($value, Order $row) => $row);
        $grid->exportable();
    });

    $response = $this->get('/admin/orders/_brume/export');
    ob_start();

    try {
        $response->baseResponse->sendContent();
    } finally {
        ob_end_clean();
    }
})->throws(InvalidArgumentException::class, 'exportUsing()');

it('writes exportUsing dates as Y-m-d H:i:s, lists joined and a switchGroup as the labels that are on', function () {
    exportOrders(1);
    exportRoutes(function (Grid $grid) {
        $grid->column('created_at', '建立時間')->exportUsing(fn () => now()->setDate(2026, 10, 29)->setTime(9, 5, 7));
        $grid->column('flags', '旗標')->exportUsing(fn () => ['甲', 2, null]);
        // amount (10) is on, the missing attribute is off.
        $grid->column('switches', '開關')->switchGroup(['amount' => '有金額', 'missing' => '缺少', 'customer' => '有客戶']);
        $grid->exportable();
    });

    expect(exportSheet($this->get('/admin/orders/_brume/export'))[1])
        ->toBe(['BR0001', '已付款', 10, '2026-10-29 09:05:07', '甲、2、', '有金額、有客戶']);
});
