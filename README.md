# brume/laravel

Brume admin panel for Laravel (Inertia + React). Work in progress (phase 1).

## Quick start

Requirements: PHP 8.3+, Laravel 12 or 13. The frontend packages (`@brume-ui/brume-*`) are public
on npmjs.com, so no registry token or `.npmrc` entry is needed.

### 1. Install the package

`brume/laravel` is not on Packagist yet. Add the GitHub repository and require it:

    composer config repositories.brume vcs https://github.com/brume-ui/brume-laravel
    composer require "brume/laravel:^0.1@alpha"

(Before the first tag exists, require `brume/laravel:dev-phase-1` instead.)

### 2. Run the installer: pick a frontend mode

**Precompiled (default).** Brume ships a built frontend; no Node toolchain in your project:

    php artisan brume:install

**`--frontend`.** Your project compiles Brume's frontend with its own Vite build (a separate
`vite.brume.config.ts`), so you can register your own React components:

    php artisan brume:install --frontend                  # Laravel 13
    php artisan brume:install --frontend --upgrade-deps   # Laravel 12
    pnpm install            # or npm install
    pnpm brume:dev          # dev server; run pnpm brume:build before deploying

Laravel 12's skeleton pins vite 7 and laravel-vite-plugin 2; Brume's toolchain needs vite 8, so
`--upgrade-deps` raises those versions in `package.json` (it keeps your `^`/`~` and never
downgrades). Without it the installer finishes the rest of the setup, then lists the conflicts and exits with a failure status.

Both modes publish `config/brume.php` and `routes/brume.php`, run the migrations and create the
first admin (it asks, or pass `--username=` and `--password=`, 8+ characters). Other options:

- `--precompiled` switches a `--frontend` project back to the precompiled frontend.
- `--force` overwrites existing frontend files and skips the switch-back question.

Sign in at `/admin` (the prefix is `brume.route.prefix` in `config/brume.php`). In precompiled
mode, re-publish the assets after every `composer update brume/laravel`:

    php artisan vendor:publish --tag=brume-assets --force

### 3. Add a page

Admin routes go in `routes/brume.php` (the file `brume.routes.host` points to). Brume loads it
inside its own route group, so every route already has the `/admin` prefix, the middleware from
`config/brume.php` and requires a signed-in admin. A list is a controller that returns a `Grid`,
registered with `Route::brumeGrid`:

```php
// routes/brume.php
use App\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::brumeGrid('orders', OrderController::class)->name('brume.orders.index');
```

```php
// app/Http/Controllers/Admin/OrderController.php
namespace App\Http\Controllers\Admin;

use App\Models\Order;
use Brume\Grid\Grid;

class OrderController
{
    public function __invoke(): Grid
    {
        $grid = Grid::make(Order::class)->title('訂單');

        $grid->column('number', '訂單編號');
        $grid->column('total', '金額')->numeric();
        $grid->column('created_at', '建立時間')
            ->display(fn ($value) => $value?->format('Y-m-d H:i'));

        return $grid->perPage(20);
    }
}
```

`Route::brumeGrid('orders', ...)` registers the list page (`GET orders`) plus the endpoints under
`orders/_brume/` that actions, inline editing, the export and remote filter options talk to. See
[Grid](#grid) for what a list can do. A page that only shows rows (no actions, editing, export or
remote filters) also works on a plain `Route::get`.

Paging (`?page=`) is handled for you. To show the page in the sidebar, add it to `menu` in
`config/brume.php` (`url` is relative to the prefix):

```php
'menu' => [
    ['title' => 'brume::menu.dashboard', 'icon' => 'layout-dashboard', 'url' => '/'],
    ['title' => '訂單', 'icon' => 'receipt', 'url' => '/orders'],
],
```

Icons are [Lucide](https://lucide.dev/icons) names. An item with `children` (each with `title`
and `url`) becomes a collapsible group.

### 4. Register a custom component (`--frontend` only)

A custom component is a React component plus one line in `resources/js/brume/components.ts`:

```tsx
// resources/js/brume/components/StockBadge.tsx
export function StockBadge({ low, context }: { low: number; context: { row: Record<string, unknown> } }) {
  const stock = Number(context.row.stock);
  return <span className={stock <= low ? 'text-danger' : 'text-text'}>{stock}</span>;
}
```

```ts
// resources/js/brume/components.ts
import { StockBadge } from './components/StockBadge';

export const components = { StockBadge };
```

Then use it by name from PHP; the array becomes the component's props, and list cells also get
`context` (`row`, `column`):

```php
$grid->column('stock', '庫存')->component('StockBadge', ['low' => 5]);
```

Dashboards take components the same way: `Dashboard::make('儀表板')->component('SalesChart')`.
Styling uses Brume's tokens as plain Tailwind utilities (`bg-surface`, `text-text-muted`,
`text-danger`, ...). In precompiled mode an unknown component shows an error that tells you to
switch to `--frontend`, instead of an empty slot.

## Grid

`Grid::make()` takes a model class or a query; the list, the `_brume` endpoints (actions, inline
editing, remote options) and the Excel export all start from that query. A complete example is
the workbench's `workbench/app/Http/Controllers/OrderController.php`.

### Routes: `Route::brumeGrid`

```php
Route::brumeGrid('shops/{shop}/orders', [ShopOrderController::class, 'index'])
    ->middleware('can:viewAny,App\Models\Order')
    ->name('brume.shop-orders.index');
```

This registers five routes: the page (`GET shops/{shop}/orders`) and, under
`shops/{shop}/orders/_brume/`, `POST actions/{action}`, `PATCH cells/{id}`, `GET export` and
`GET options/{source}`. Every endpoint runs **the page's own controller action**, so it goes
through the same middleware (group, route and controller middleware), route model binding and
route caching as the page; the `Grid` the action returns then answers the endpoint instead of
rendering the page. Modifiers chained on the result (`middleware`, `can`, `where*`,
`scopeBindings`, `withTrashed`, `domain`, ...) apply to all five routes; `name()` names the page.

A grid that declares actions, inline editing, an export or remote filter options on a plain
`Route::get` throws in the `local` environment; elsewhere those features are left out and a
warning is logged.

### Columns

```php
$grid->column('number', '訂單編號')->primary()->hideable(false);  // links to editUrl()
$grid->column('customer.name', '客戶');                           // a relation (eager load it)
$grid->column('amount', '金額')->numeric()->sortable()->width('8rem')
    ->display(fn (int $value) => number_format($value))           // shown value…
    ->exportUsing(fn (int $value) => $value);                     // …and the Excel cell
$grid->column('updated_at', '更新時間')->hidden();                 // off until shown from 顯示欄位
$grid->editUrl(fn (Order $order) => route('brume.orders.edit', $order));
```

- Column keys are unique, and `_meta` is reserved.
- **A column keyed `id` is the row's id** (`row.id`, which actions, inline edits, selection and the
  export send back), so it shows the model key unchanged: `display()`, `label()`, `image()`,
  `downloadable()`, `table()` and inline editing throw on it. For a formatted number, declare
  another key that reads the model:
  `$grid->column('no', '編號')->display(fn ($value, Order $row) => sprintf('#%05d', $row->id))`.
- `editUrl()` and the URLs of `link()`, `button()`, `image()` and `downloadable()` may only be
  `http`, `https`, `mailto`, `tel` or relative. Another scheme (`javascript:`) makes `editUrl()`
  throw in `local` and is dropped (and logged) elsewhere; a display shows plain text instead.

### Sorting, search and paging

- `sortable()` on a column lets its header sort (`?sort=amount`, `?sort=-amount`); a dotted key
  needs the closure, `sortable(fn (Builder $query, string $direction) => ...)`.
  `defaultSort('created_at', 'desc')` applies when the URL asks for none. Every sort ends with the
  primary key, so pages never repeat or skip rows.
- `quickSearch('number', 'customer.name', fn (Builder $q, string $term) => ...)` searches `?q=`
  with a case-insensitive "contains" on each column, joined with OR;
  `quickSearchPlaceholder('搜尋訂單編號或客戶')` sets the hint.
- `perPage(15)` and `perPageOptions([15, 50, 100])` (`?per_page=`). Invalid paging, sort or
  per-page values fall back to the defaults instead of failing.
- `emptyState('還沒有訂單', '建立第一筆訂單後會顯示在這裡。', '新增訂單', route(...))` replaces the
  empty list's copy.

### Filters

`$grid->filters()` holds the panel filters (under 「篩選」), hidden filters, selector pills and
scopes; `$column->filter()` adds one in the column header. Each reads `filter[key]` from the URL.

```php
$filters = $grid->filters()->columns(2);
$filters->equal('status', '狀態')->select(Order::STATUS_LABELS)->default('paid');
$filters->in('category', '分類')->multipleSelect($categories);
$filters->between('amount', '金額')->number();
$filters->between('created_at', '建立時間')->date();
$filters->equal('customer', '客戶', 'customer_id')->remoteSelect(Customer::where('shop_id', $shopId), 'name');
$filters->newline();
$filters->group('stock_rule', '庫存條件', ['equal', 'gt', 'lt'], 'stock')->number();
$filters->where('vip', '只看 VIP', fn (Builder $q, $value) => $q->whereHas('customer', ...));
$filters->hidden('customer_id');                    // ?filter[customer_id]=5 from a link
$filters->scope('this_month', '本月', fn (Builder $q) => $q->where('created_at', '>=', now()->startOfMonth()));
$filters->selector('stage', '狀態', Order::STATUS_LABELS, multiple: true, column: 'status');

$grid->column('sku', '貨號')->filter('startWith');
$grid->column('price', '售價')->numeric()->filter('between');
```

- Conditions: `equal`, `notEqual`, `like`, `ilike`, `startWith`, `endWith`, `gt`, `lt`, `ngt`,
  `nlt`, `between`, `in`, `notIn`, `findInSet`, `date`, `day`, `month`, `year`, `group` (an
  operator picked next to the value), `where` and `whereBetween` (your closure). The third
  argument is the database column when it differs from the key (`meta->color` for a JSON path,
  `customer.name` through a relation).
- Inputs: `text()` (the default), `number()`, `date()`, `select()`, `multipleSelect()`,
  `radio()`, `checkbox()`, `remoteSelect()` and `remoteMultipleSelect()`.
- `remoteSelect(Model::class or a Builder, $labelColumn, $valueColumn = key, $searchColumns)`
  loads its options page by page from the `options` endpoint. The options come from the query you
  pass, not from the grid's: on a grid limited to one shop, pass a Builder limited the same way.
  Its label, value and search columns may not be in the model's `$hidden`.
- `default()` gives a filter a value **only while the URL has no `filter` parameter at all**. Any
  `filter` parameter turns every default off, including an empty `filter[status]=` and a bare
  `filter=`: when the user removes the last filter (a chip's ✕, a header filter's 「清除」,
  deselecting a pill, 「清除所有篩選」), the page sends `filter=`, so a removed default stays removed
  and 「清除所有篩選」 shows the unfiltered list.
- Header filters (`$column->filter($type, $options)`) take `between`, `checkbox`, `equal`, `gt`,
  `in`, `input` (contains), `like`, `lt`, `ngt`, `nlt` and `startWith`; `in` and `checkbox`
  need `$options`.

Some conditions depend on the database; see
[Filters that depend on the database](#filters-that-depend-on-the-database).

### Displays

One display per column; the value is the attribute (after `display()`):

| Display | Shows |
| --- | --- |
| `label($options, $tone)`, `badge($tone)` | a pill per value (`$options` maps values to text, or to `[text, tone]`) |
| `link(fn ($row) => $url, external: true)`, `button(fn ($row) => $url)` | a link, or a link styled as a button |
| `image($width, $height)`, `downloadable()` | one or more images / files (URLs, or `['url' => ..., 'name' => ...]`) |
| `limit($length)`, `copyable()`, `qrcode($size)`, `progressBar($max)` | shortened text with the rest on click, a copy button, a QR code, a bar |
| `table(['size' => '尺寸', ...])` | a small table of a list of rows, only the declared keys |
| `expand(fn ($row) => Content::..., $label)`, `modal(fn ($row) => Content::..., $title, $label)` | content under the row, or in a dialog: `Content::text()`, `Content::table()`, `Content::component()` |
| `component('StockBadge', $props)` | your own React component (`--frontend`) |

### Inline editing

```php
$grid->column('status', '狀態')->label($statuses)->editable(Editor::select(Order::STATUS_LABELS));
$grid->column('slogan', '標語')->editable(Editor::text(20), ['required', 'string']);
$grid->column('price', '售價')->numeric()->editable(Editor::number(0, 99999, 10), ['required', 'integer']);
$grid->column('channels', '通路')->editable(Editor::checkbox($channels)); // cast the attribute to an array
$grid->column('is_active', '上架')->switch();
$grid->column('flags', '標記')->switchGroup(['is_featured' => '精選', 'is_new' => '新品']);
```

Editors: `text`, `textarea`, `number`, `select`, `radio`, `checkbox`, `switch` and `switchGroup`.
The second argument holds Laravel validation rules; the options of `select`, `radio` and
`checkbox`, a switch's boolean and the editor's bounds are added for you. A save is a `PATCH` to
the `cells` endpoint with the row looked up in the grid's query, and a 422 shows the message next
to the cell.

**Inline edits bypass `$fillable`.** The column declaration is the allowlist: only a column
declared `editable()`, `switch()` or `switchGroup()` can be written, and only the declared
attributes, with your rules. Declare a column editable only for users who may change it (see
[Authorizing](#authorizing)). Relation columns (dotted keys) cannot be edited yet.

### Actions

```php
use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\Feedback;

$grid->rowActions(
    Action::make('ship', '標記出貨')->icon('truck')->exposed()
        ->visible(fn (Order $order) => $order->status === 'paid')
        ->handle(fn (Collection $orders) => $orders->each->ship()),
);
$grid->batchActions(
    Action::make('ship', '標記出貨')->confirm('將 {count} 筆訂單標記為已出貨？')
        ->handle(fn (Collection $orders) => Feedback::toast('success', "{$orders->count()} 筆已出貨")->refresh()),
);
$grid->tools(Action::make('sync', '同步庫存')->icon('refresh-cw')->handle(fn () => Inventory::sync()));
$grid->deletable();   // 刪除 for rows and the selection, with the confirm copy
```

- Row actions sit in each row's ⋯ menu (and its right-click menu); up to two `exposed()` ones
  also show as buttons. Batch actions show while rows are selected; tools sit in the toolbar.
- `handle(fn (Collection $models, Request $request): ?Feedback)` gets one model for a row action,
  the selection for a batch, none for a tool. `danger()`, `confirm($title, $description,
  $confirmLabel)` (`{count}` is the number of rows), `shortcut('c')` and `icon()` style it.
- Return `Feedback` to say what happens next: `Feedback::toast('success'|'info'|'warning'|'error',
  $message)`, `->refresh()`, `->redirect($url)`, `->download($url, $filename)`. Without one, the
  page shows 「已完成「{label}」」 and refreshes. Redirect and download URLs are checked like
  `editUrl()`.
- **Actions run on the grid's base query, not on the list's filters** (R23). The ids in a request
  are looked up in `Grid::make()`'s query, with its conditions and global scopes, so an id the
  list could never show answers 404; the search, filters, scope and paging of the list the user
  was looking at are not applied again. Put a restriction that must hold for actions (a shop, a
  tenant) on the base query, not in a filter.
- `deletable()` deletes each row with `$model->delete()`, so model events fire and soft deletes
  are kept; rows a `deleting` listener keeps are reported as not deleted.

#### Transactions and one-time actions

An action runs inside a database transaction on the model's connection, and a thrown exception
rolls it back. The rollback covers only database writes on that connection: mail, HTTP calls,
files, queued jobs and writes on other connections have already happened. Run those after the
commit with `DB::afterCommit()`, or make the job or listener `ShouldQueueAfterCommit` and the
event `ShouldDispatchAfterCommit`.

For a one-time action (refund, ship, issue an invoice), check the state again inside the action
with a row lock, so two clicks or two admins cannot both run it. A `ValidationException` rolls the
whole action back and shows its message:

```php
Action::make('refund', '退款')->handle(function (Collection $orders) {
    $locked = Order::query()->whereKey($orders->modelKeys())->lockForUpdate()->get();

    if ($locked->contains(fn (Order $order) => $order->status !== 'paid')) {
        throw ValidationException::withMessages(['ids' => '有訂單已經退款，請重新整理後再試一次。']);
    }

    $locked->each->update(['status' => 'refunded']);
});
```

### Export

`exportable($filename = null)` adds 「匯出 Excel」: all matching rows, the current page or the
selected rows, as an `.xlsx` of the columns (the shown value, or `exportUsing(fn ($value, $row) =>
...)`; leave a column out with `exportable(false)`). The export uses the list's search, filters and
sort; text that looks like a formula is written as text. The file name defaults to
`{title}-{Y-m-d-His}.xlsx`.

### Authorizing

There are no per-user permissions in Brume yet (phase 4). Two Laravel tools cover it:

- **Middleware on the route**, for the whole list and its endpoints:
  `Route::brumeGrid('orders', OrderController::class)->middleware('can:viewAny,App\Models\Order')`.
  The endpoints run the same middleware, controller middleware included.
- **Declare only what the user may use.** The endpoints answer from the `Grid` your controller
  returns, so an action, an editable column or the export you do not declare does not exist for
  that request: it is not shown, and a request for it is refused (404 for an action or the
  export, 422 for a cell that is not editable).

  ```php
  if ($request->user()->can('refund', Order::class)) {
      $grid->rowActions(Action::make('refund', '退款')->danger()->handle(...));
  }
  $column = $grid->column('status', '狀態');
  if ($request->user()->can('update', Order::class)) {
      $column->editable(Editor::select(Order::STATUS_LABELS));
  }
  ```

  `visible(fn (Order $order) => $request->user()->can('ship', $order))` decides it per row: the
  action is left out of that row's menu, and a request naming the row answers 403.

### More about columns and queries

- **Relation columns.** A dotted column such as `customer.name` reads the relation on every row.
  Eager load it on the base query, or the list and the export run one query per row (N+1):

  ```php
  $grid = Grid::make(Order::query()->with('customer'));
  $grid->column('customer.name', '客戶');
  ```

- **Hidden attributes.** A column that reads an attribute in the model's `$hidden` (directly or
  through a relation) is refused in the list unless it has a `display()` closure, which decides
  what is shown. The export refuses it unless the column has `exportUsing()` (or
  `exportable(false)`), and inline editing refuses it behind a `display()` closure, because the
  stored value would be sent as `_meta.raw`. `table()` cells and remote options refuse hidden
  attributes too.
- **Decimals.** MySQL returns a `decimal` column as a string. Cast it (`'decimal:2'`) or declare the
  column `numeric()` so the export writes it as a number.
- **String keys** are matched exactly. With MySQL's default case-insensitive collation the
  database also returns the row `abc` for the id `ABC`; Brume answers 404 for it, as SQLite and
  PostgreSQL do.
- **Host tests.** The endpoints keep working under `$this->withoutMiddleware()` and `Event::fake()`.

### Filters that depend on the database

Brume uses only Laravel's cross-database query builder, plus an ANSI `LIKE ? ESCAPE '!'` with
`LOWER()` for case-insensitive matching. These conditions still behave differently between
databases; `FilterCondition::driverNote()` returns the same notes.

| Condition | SQLite | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| `like`, `startWith`, `endWith` | case-insensitive for ASCII letters | follows the column collation (the default `_ci` ignores case) | case-sensitive |
| `ilike` | `LOWER()` folds ASCII letters only (`É` and `é` differ) | `LOWER()` on both sides | `lower()` takes text columns only; a numeric column errors |
| `findInSet` | portable `=` / `LIKE` on the comma-separated list | same (no `FIND_IN_SET()`) | same |
| `in` / `notIn` with `->json()` | no `whereJsonContains`: falls back to `LIKE` on `"value"` in the JSON text, string items only, ASCII case ignored | `whereJsonContains` | `whereJsonContains` |
| JSON path column (`meta->color`) | needs JSON1 (built in since 3.38) | 5.7 or later | `json` or `jsonb` column |

`findInSet` uses no index, and its items must be stored without spaces around the commas.

### Not-found page

A signed-in visit to an unknown path under the Brume prefix (for example `/admin/nope`) answers
404 with the error page inside the shell: 「找不到這個頁面」, with a 「回到首頁」 link. A guest is sent
to the login page; paths outside the prefix are left to Laravel.

- A JSON request that is not an Inertia visit (`Accept: application/json`, as `fetch` or axios
  send it) gets `{ "message": "找不到這個頁面" }` with 404 instead of the page.
- The not-found route is a GET fallback, so another method on an unknown path (`POST
  /admin/nope`) answers 405 Method Not Allowed.
- Laravel matches `Route::fallback` routes in the order they were registered. If your app
  registers its own `Route::fallback` before Brume's service provider boots, that one answers
  `/admin/<unknown>` too. Register the app's fallback after Brume's, or limit it to your own
  prefix or domain.
- With an empty `route.prefix` and no `route.domain`, Brume registers no fallback: it would catch
  every unknown path of the app and send guests to the admin login. Unknown paths then get
  Laravel's own 404. On an admin domain of its own (`route.domain`) the fallback stays.

## Not yet available

These are planned for later sub-plans and phases:

- **Rich text, color picker and Show pages**: phase 3; use `textarea` until then.
- **Custom calendars**: native `date` / `datetime-local` inputs are retained; revisit after user trials.
- **Orderable, Tree and DialogTree**: phase 3.

## Development

    composer install
    composer test      # Pest (SQLite)
    BRUME_TEST_DB=mysql DB_PORT=3307 DB_PASSWORD=secret composer test   # the same suite on MySQL
    composer lint      # Pint
    composer serve     # workbench at http://127.0.0.1:8100/admin

### Workbench

The workbench is a demo admin (sign in as `admin` / `admin1234`) that runs the way a project
installed with `brume:install --frontend` does: its own frontend entry in
`workbench/resources/js/brume/` (`app.tsx` as in the stub, plus `components.ts` registering the
demo components) is built by Vite into the testbench skeleton's `public/build-brume`. You need
PHP and pnpm; `composer serve` (and `composer build`) installs `frontend/` with the frozen
lockfile, builds the workbench frontend (`composer build-frontend`, i.e.
`pnpm --dir frontend build:workbench`), recreates the SQLite database with seeded demo data and
serves it. After editing the workbench's `.tsx` files, run `composer build-frontend` and reload.

What's in it:

- **儀表板**: brume-react's chart components (`StatCard`, `LineChart`, `DonutChart`,
  `BarChart`) fed from the seeded orders: `DashboardController` computes the figures and passes
  them as props to the registered `KpiRow` and `ChartCard` components (`components/`).
- **訂單**: the phase-2 Grid set up like an everyday list (`OrderController`, `Route::brumeResource`)
  over 60 seeded orders: newest first (`defaultSort`), quick search on number and customer,
  sortable 金額 and 建立時間, filters (狀態 select, 分類 multipleSelect, 金額 and 建立時間 ranges,
  a remote 客戶 select), the 本月 scope and a 狀態 selector, the status as a `label()` edited
  inline with a select, 「標記出貨」 shown on paid rows, 「顯示訂單編號」 and 刪除 in the ⋯ menu,
  batch 標記出貨 and 刪除, and the Excel export. Create/edit open real modal forms; 客戶 is required
  (50 characters), with currency 金額, 狀態, 分類 and 備註. After saving choose list, edit or create.
  Open orders also have a 取消訂單 action requiring a reason; 快速新增 needs only 客戶 and 金額.
- **Grid 示範** (`/admin/grid-showcase`, `GridShowcaseController`): 60 seeded `showcase_items`
  with every display, every inline editor (`switch` and `switchGroup` included), all 11
  column-header filters, and panel filters for JSON arrays (tags and channels), a
  JSON path (`meta->color`), `group`, a remote select, `whereBetween`, `newline()` and
  `columns(2)`, plus a `hidden` filter (`?filter[owner_id]=2`). 負責人 is a relation column
  (`owner.name`), eager loaded with `with('owner')`. Its images and downloads come from
  `/admin/_demo/swatch/{color}.svg`, so it needs no network. Its real product form uses four sections
  (基本資料, 銷售, 媒體, 其他), so auto presentation opens a page with a contents navigation. It includes
  sortable gallery uploads with per-image links, a cover and documents, date range, nested meta,
  contacts and a hidden password. Featured name/SKU fields are readonly. Price uses two decimal
  places; date, currency and tags are also inline editors. Uploaded demo files use `brume_demo`
  (`public/uploads`, URL `/uploads`) and need no `storage:link`.
- **元件展示**: every public brume-react component (buttons, inputs, Field, Card, EmptyState,
  Skeleton, DataTable + Pagination, Dialog, confirm, toasts, PageHeader, the charts; breadcrumbs
  come from the shell) in the real shell, as one registered component
  (`components/ComponentShowcase.tsx`). Its 圖表 section links to the dashboard.
- **系統 → 管理員**: the admins list with 36 seeded admins.
- Not in the menu, used by the e2e suite: `/admin/_demo/toast` (flashes the same toast every
  time) and `/admin/_demo/missing-component` (the guidance shown for an unregistered component).

The demo menu, routes and dashboard live in `workbench/` and are only active on the dev server,
not under Pest (`tests/Feature/WorkbenchGridsTest.php` registers the two grids itself). Laravel
ships English validation messages only, so the workbench adds `workbench/lang/{zh_TW,zh_CN}/validation.php`
for the common rules: an inline edit that fails validation reads in Chinese. `WORKBENCH_EMPTY_LIST=1 composer serve` empties the 管理員 list, to check its
empty state. The workbench uses SQLite and the session cookie `brume_workbench_session`, so it
does not clash with other local Laravel apps.

Precompiled mode (`dist/`) is what `brume:install` without `--frontend` uses; it is covered by
`check-dist` and the install smoke tests below, not by the workbench.

## End-to-end and install smoke tests

    pnpm --dir e2e exec playwright install chromium   # once
    composer e2e      # Playwright + axe against the workbench on port 8100

`composer e2e` starts its own server (`e2e/serve.sh`: builds the workbench frontend, fresh seeded
database, `display_errors=1` so PHP warnings printed into a response fail the suite). It never
reuses a running server: stop `composer serve` first, or run the suite on another port with
`E2E_PORT=8103 composer e2e`. Both use the same SQLite file, so the suite reseeds the database a
running `composer serve` reads. `e2e/tests/grid.spec.ts` ships and deletes seeded orders, so
it runs as its own Playwright project after the other specs. `form.spec.ts` then runs in a separate
`forms` project depending on `grid`, with retries disabled, so its mutations cannot race the seeded
counts. It covers real persistence, keyboard/dirty guards, uploads/extras/sort, action inputs,
quick create, inline editors, axe and 1440×900 / 390×844 light/dark geometry and screenshots.

CI and `composer e2e` install the published UI packages from `frontend/pnpm-lock.yaml` with a
frozen lockfile before building the workbench. The frontend dist check and install smoke jobs use
those published packages too.

Linux screenshot generation requires explicit trusted push `[update-snapshots]` or the workflow's
`update-snapshots` dispatch input. The full browser suite must pass before its bot commits only Linux
PNGs. Review every generated image, then run normal non-update CI; generation itself is not acceptance.

    composer smoke -- 12 precompiled                # or: 12 vite, 13 precompiled, 13 vite

`bin/smoke-install.sh` creates a new Laravel app in `$TMPDIR`, installs this checkout through a
path repository, runs `brume:install`, serves it on port 8101 and checks login, `/admin` and an
`X-Inertia` request with curl. Set `KEEP=1` to keep the scratch app. If `brume:install --frontend`
stops on package.json conflicts (the Laravel 12 skeleton pins vite 7 and laravel-vite-plugin 2,
which Brume's vite 8 toolchain cannot use), the script re-runs it with `--upgrade-deps` and prints
a WARN; add `--no-upgrade` as a third argument to fail instead.

## Frontend assets

`dist/` holds the precompiled frontend and is committed, because Composer installs this package
from git. It is built from `stubs/frontend/app.tsx` (the same file `brume:install --frontend`
copies into projects) with an empty component registry, against the exact
`@brume-ui/brume-*` version in `Brume\Version::UI`.

    pnpm --dir frontend install --frozen-lockfile
    pnpm --dir frontend build        # rebuild dist/
    pnpm --dir frontend check-dist   # fails if dist/ differs from a fresh build

To bump brume-ui, change `Brume\Version::UI` and the five versions in `frontend/package.json`
together (`VersionTest` checks they match), rebuild and commit `dist/`.

When developing brume-ui at the same time, link the local packages instead of the published ones
(do not commit the resulting `package.json` / lockfile changes):

    cd frontend
    pnpm link ../../brume-ui/packages/tokens
    pnpm link ../../brume-ui/packages/schema
    pnpm link ../../brume-ui/packages/react
    pnpm link ../../brume-ui/packages/renderer
    pnpm link ../../brume-ui/packages/inertia

## Forms and resources

Extend `Brume\Resource\Resource` and register it in your `routes/brume.php`. Its `query()` is the
shared scope for the grid, edits, updates, uploads and deletion; override it for tenant isolation.
All routes include `web` and `brume.auth`, and every chained shared modifier (middleware, `can`,
domain, constraints, scoped bindings and trashed binding) also reaches the Grid and upload routes.

```php
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Resource\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

class OrderResource extends Resource
{
    protected string $model = Order::class;
    protected string $name = '訂單';
    protected bool $deletable = true;

    protected function query(): Builder
    {
        return parent::query()->where('shop_id', auth()->user()->shop_id);
    }

    protected function grid(Grid $grid): void
    {
        $grid->column('number', '訂單編號')->primary()->sortable();
        $grid->column('customer', '客戶');
    }

    protected function form(Form $form): void
    {
        $form->text('number', '訂單編號')->required()->rules('max:40')
            ->messages(['required' => '請填寫訂單編號']);
        $form->text('customer', '客戶')->required();
        $form->currency('amount', '金額')->decimals(2);
        $form->image('receipt', '收據')->disk('public')->directory('receipts');
        $form->saving(function ($record, &$values) {
            if (! $record->exists) {
                $record->shop_id = auth()->user()->shop_id;
            }
        });
        $form->viewUrl(fn ($record) => '/orders/'.$record->getKey());
    }
}

Route::brumeResource('orders', OrderResource::class)
    ->middleware('can:manage-orders');
```

The example model needs columns for the declared fields and a decimal cast for `amount`.
The default name prefix is the route group's name plus the dotted URI, e.g. `brume.orders`;
`->names('orders')` chooses another prefix within the group, and
`->name('edit', 'orders.modify')` overrides one action's route name within that group. Names end in `.index`, `.create`,
`.store`, `.edit`, `.update`, `.upload` and `.record-upload`. The form identity is the same prefix
for every action and record. Upload routes execute `store` (create) or `update` (edit); all Grid
endpoints execute `index`. A controller's `middleware()->only()` / `except()` must use those action
names. `create` and `edit` render the generic `brume/page` Inertia component.

Fields: `text`, `textarea`, `email`, `url`, `mobile`, `password`, `hidden`, `display`, `number`,
`decimal`, `currency`, `date`, `datetime`, `dateRange`, `datetimeRange`, `select`, `multipleSelect`,
`radio`, `checkbox`, `switch`, `tags`, `image`, `multipleImage`, `file`, `multipleFile`.
Use `section`, `row`, `columns`/`column`, `block`, `divider`, `embeds` and `tabs` to arrange them.
`layout('stacked'|'horizontal')`, `presentation('auto'|'modal'|'page')` and `size('auto'|'sm'|'md'|'lg')`
control presentation; auto uses page mode with three sections or more than sixteen fields.

`rules()` and `messages()` use Laravel validation. The server returns 422 `{message, errors}`
with dotted field paths relative to `values`; client rules use the same translated messages.
Only declared editable fields are written; `readOnly`, `disabled` and `display` inputs are ignored.
Model hidden attributes stay private unless the field explicitly declares `value()`; password
fields always load blank and a blank submitted password preserves the stored password.

Hooks: `creating($form)`, `editing($record, $form)`, `submitted($values)`,
`saving($record, &$values)`, `saved($record)`, `deleting($record)`, `deleted($record)`.
`submitted` or `saving` can return `Feedback` to cancel saving; `saved` can return success feedback.
For resource deletion, `deleting` returning Feedback cancels and rolls back the **whole batch**,
including earlier rows. `deleted` is a void hook and exceptions roll back the batch. A model's
own `deleting` event returning false instead vetoes only that row, retaining partial-success
feedback. Hard-delete file cleanup captures all declared stored uploads before hooks (including
hidden-backed, disabled and readonly fields) and waits for the actual outer transaction commit;
soft deletion, veto, cancellation and rollback preserve files.

Submit `{values: {...}}` to the schema's `submit.url` with its `submit.method`. Success includes
`id`, `feedback` and safe `urls` for list, edit, create and optional view. `after()` selects the
allowed post-save destinations. Request create/edit with `X-Brume-Form: 1` to receive a JSON
`PageEnvelope` instead of Inertia; both representations vary on `X-Brume-Form`, preserving other
Vary values. Missing scoped records return 404, as an ErrorPage for pages or ErrorResponse for
JSON/Form-header requests. Every POST/PUT uses CSRF protection; send the encrypted `X-XSRF-TOKEN`.

Uploads accept `field` and `file` at the schema's `uploadUrl`. Use `accept`, `maxSize`, `max`,
`sortable` and `extra` to configure them. Configure `brume.uploads.disk`, `temp_disk`, TTL,
connection allowlist and cleanup scheduling as described below; run Laravel's scheduler so the
hourly `brume:uploads:clean` can execute. Resource forms set their stable identity automatically.

## Temporary form uploads

Forms containing uploads require a stable, explicit `Form::id('resource-name')`. Use the same
identity when rendering the form, accepting temporary uploads, and saving; separate forms must
use separate identities. Temporary tokens also bind the configured Brume auth guard, user, full
field path, and root edit record (or create context).

`Brume\Uploads\UploadController::store(Form $form, Request $request)` accepts `field` and `file`.
Host routes must resolve the scoped record first and apply the resource's middleware, `web`,
`brume.auth`, and `throttle:60,1`. Resource upload endpoints are added by the resource registrar.
The built-in owner-only image preview is `brume.uploads.preview`.

Configure disks, the temporary TTL in minutes, and maximum size in KB under `brume.uploads`.
`uploads.safe_extensions` is a positive allowlist of server-detected extensions; general file fields
also enforce it, even with an explicit MIME allowlist. Unknown and active executable formats are
rejected by default. Hosts may extend this list only for reviewed formats, and must configure
public storage so uploaded files can never execute.
`uploads.connections` defaults to the application's default database connection. For models on
other connections, explicitly list every allowed connection and migrate `brume_uploads` on each;
preview and cleanup inspect only that list. No connection name comes from request input.

A single upload without extras stores a path string or null. Multiple uploads store ordered path
arrays; declared extras produce `{path, extra}` entries. A single upload with extras also stores
`{path, extra}`. Multiple uploads, extras, and embedded attributes need an `array` or `json` model
cast, unless `saveUsing()` supplies their writer. Undeclared request metadata and client URLs are ignored; existing protected/server metadata is preserved.

Archival runs inside the model transaction before fill/save. It copies files and consumes token
rows transactionally; outer rollback removes new copies and restores token eligibility. Actual
outer commit deletes temporary files and removed old files. Cleanup uses exact-connection lifecycle events for physical outer commit and rollback, including
Laravel12 committed-child rollbacks; shared-manager native callbacks do not establish ownership. Connection events must be enabled before archival;
an unsupported connection fails before any file mutation. Cleanup failures are reported through
Laravel’s exception handler without changing a completed save outcome; operators must investigate
and remove leftover files. Storage outages are not automatically recovered by this cleanup.
`brume:uploads:clean` deletes expired temporary rows/files and old untracked `brume-tmp/` files;
`uploads.schedule_cleanup` registers it hourly by default.

### Quick create and action inputs

`Grid::quickCreate(fn (Form $form) => $form->text('name')->required())` adds
`POST {list}/_brume/quick-create` and the list's `quickCreate` metadata. Resource
controllers can declare the same fields in `protected quickCreate(Form $form): void`.
Standalone grids must configure `Grid::editUrl()` or set `Form::urls()` inside the
quick-create closure with the real saved record's edit URL before persistence.
Quick create uses the regular form validation, hooks and cancellation response.

`Action::form(fn (Form $form) => $form->textarea('reason')->required())` collects
input before execution. Its handler receives `(Collection $models, Request $request,
array $input)`; the third argument contains only validated editable declarations,
or `[]` when no form was declared. Validation happens before the action transaction.
Quick-create and action fields must be flat and cannot contain uploads or layout
containers. All writes retain the list route's middleware and CSRF protection.

Inline editing also supports `Editor::date(withTime: true)`,
`Editor::currency(symbol: 'NT$', decimals: 2)` and `Editor::tags(['a' => 'Suggestion'])`.
Currency defaults to zero decimals; choose two explicitly for values such as
`"1280.50"`. Decimal normalization uses strings. Tags options are suggestions and
custom strings are accepted; the model attribute must have an array/JSON cast.

### Conditional, composite and recursive declarations (phase-2 source)

Conditions are immutable JSON ASTs. Equality preserves JSON types; money compares exact canonical
strings. Switches used as condition controllers require boolean wire values (`true`/`false`);
ordinary legacy switches continue accepting Laravel’s 0/1 forms. Readonly controllers use their
authoritative stored value, and an invalid editable controller keeps dependent validation active.
`Conditions::root()` and `Conditions::row()` return scope/path declarations. A row path
starts at the enclosing repeater or selected item, not at a numeric segment guessed from its name.

```php
use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Options\OptionSource;

$form->text('reason')->required()
    ->showWhen(Conditions::eq(Conditions::root('status'), 'rejected'));
$form->text('reference')->enableWhen(Conditions::not(Conditions::empty(Conditions::root('name'))));
$form->tags('primary')->excludeSelectedIn([Conditions::root('secondary')]);

$form->repeater('addresses', 'Addresses', function (Form $row) {
    $row->text('label')->required();
    $row->text('instructions')->showWhen(Conditions::eq(Conditions::row('label'), 'Office'));
})->min(1)->max(5)->distinct('label');

$form->component('address', '地址', 'TaiwanAddress')->attributes([
    'zipcode' => 'zipcode', 'county' => 'county', 'district' => 'district', 'address' => 'address',
])->required()->childRules([
    'zipcode' => ['required', 'regex:/^[0-9]{3}$/D'],
    'county' => ['required', 'string'], 'district' => ['required', 'string'],
    'address' => ['required', 'string'],
]);
$form->component('total', 'Total', 'Calculator')->displayOnly();
```

`eq`, `ne`, `in`, `empty`, `all`, `any`, and `not` serialize through `toArray()`. Unknown paths,
activity cycles, reserved identity paths and overlapping composite attribute mappings fail during
declaration resolution. Symmetric exclusions are valid. Hidden model attributes remain protected;
conditions that require a protected model attribute are rejected. Readonly/disabled controllers use
stored/default values, and invalid controlling values keep dependents active for validation.
Inactive submitted values do not enter the writable allowlist. Flat Action and quick-create inputs
support conditions, while remote, exclusion, recursive and custom declarations remain Form-only.

Composite objects write only mapped keys; unknown keys fail validation and missing keys leave their
attributes unchanged. Explicit null clears the mapped attributes when the declared rules permit it.
No temporary address column or saving hook is required. `childRules()` uses relative paths, including
explicit `*` segments inside the custom JSON value. Child rules are server-only and produce errors
such as `address.zipcode`; supported root rules remain in the normal `ClientRule[]` protocol.

JSON repeaters require an array/JSON cast. Stored rows carry `_brume: {key}`; old plain arrays receive
deterministic keys scoped to the root record, declaration, ancestor identities and original position.
The first save persists those keys. New rows use client UUIDs and cannot inherit another stored row's
readonly values. JSON mode rejects `_brume.id`; metadata is not a model attribute mapping.
All JSON child readonly/rules callbacks receive the original nullable **root Form record**, even when
JSON is nested inside a related row. A conversion carrier is never passed to these callbacks.

Relation repeaters use `->relation('children')->orderColumn('position')`. `relationPicker()` takes an
`OptionSource`, supports `multiple(bool)`, `relation()`, `associateBy()`, `restoreRemoved()`, optional `orderColumn()` and
`extra(fn (Form $extra) => ...)`. Selected values are `{id, ...declaredFields}`, without an `extra`
wrapper. The selected id is distinct from any intermediary child id. Direct HasMany picker
foreign keys must be nullable; restoration requires a soft-deletable intermediary relation.
Related-row evaluation callbacks receive the parent-owned model (the actual pivot for BTM extras);
new associations receive null even if their source model already exists. JSON evaluation retains the
original nullable global root. Mutating `fill`/`saveUsing` callbacks always receive the real execution
storage target; this is distinct from evaluation authority. Explicit Eloquent bindings cannot live
under a JSON-row storage owner; use relation-mode rows or JSON remote choice IDs instead.

`FormDef.rowFields` contains resolved readonly/client-rule snapshots for initial rows, keyed by the
ordered stable `RowContext`. These are presentation metadata outside `values`; the server never
uses submitted snapshots as authorization. An explicit empty rules list clears template rules.
New client rows use template defaults; JSON templates retain the global root context. Reordering
preserves resolved capabilities and inherited parent locks still apply.

```php
$source = OptionSource::make(Product::class)->label('name')->search(['name'])
    ->columns(['name' => 'Product'])->depth('depth');
$form->select('product_id')->remote($source, ['category' => Conditions::root('category_id')]);
$form->relationPicker('freebies', 'Freebies', $source)->relation('freebies')
    ->associateBy('product_id')->restoreRemoved()
    ->extra(fn (Form $row) => $row->number('quantity')->required());
```

`OptionSource` also accepts a Closure receiving `OptionContext` (readonly `record`, `dependencies`,
`rowContext`) and returning a scoped Builder; `sections(labels, scope)` declares section metadata.
Resource routes generate the options and temporary-upload endpoints and inherit the resource's
route modifiers, authentication and scoped record lookup. No application AJAX endpoint or relation
saving hook is needed. A remote declaration without a bound endpoint throws. Form saves validate
and lock the complete graph on the root connection before executing any write or upload copy.
See [Transactional form relations](docs/relations.md) for eligibility, callback/storage contexts,
source-owned file boundaries, token binding, physical transaction cleanup and the additive nested
upload migration (including its intentionally nonshrinking rollback).

The workbench `/admin/compositions` resource demonstrates model-owned tiers → thresholds → gifts,
pivot extras, BelongsTo extras, direct HasMany selection, JSON-in-JSON notes, conditional required
values, dependent sources, mapped addresses, custom inline editing and nested Uploads. It uses
separate deterministic tables/seeds so existing Order/GridShowcase examples retain their data.
Playground `/#/form-composition` supplements the focused relation/repeater routes. Source acceptance
uses the reviewed immutable UI checkout; ordinary dist/install checks still use actual published
packages until the alpha.13 release gate.

Custom inline cells use `Editor::component('TaiwanAddress', props: [], rules: [...])` and optional
`->attributes([...])` with the same safe mapping. The server rule map uses `''` for the entire value
and relative keys for children; Column `editable(..., rules: ...)` still merges root rules.
PATCH stays `{column, value}`. Custom nested 422 keys remain `column.child`; ordinary editors retain
their previous error shape. Custom JSON retains blank strings where host middleware permits them.
Inline registry eligibility does not grant server authorization.

Owned JSON submission paths preserve object/list container kinds for custom values, including `{}`
and nested empty objects. Laravel validation receives an associative view for its array/child rules;
conditions, saving hooks and custom persistence retain semantic objects (`stdClass`) and lists.
Middleware trimming and blank-to-null transformations still apply. Saving hooks receive these values
before mutation; their replacements are never overwritten by a later raw-request restoration.
JSON `values`/Action `input` envelopes must be objects, not lists. Programmatic PHP array submissions
retain their PHP representation. Custom stored readback restores shapes only for ordinary declared
`array`/`json`/`object` casts, using current raw attributes; application accessors/readers own their
returned semantics. This does not decode arbitrary text columns or change Laravel globally.


### Register fields and displays in a custom frontend

```tsx
createBrumeApp({
  mode: 'vite',
  components, // Existing dashboard/ComponentSlot registrations remain independent.
  fields: { TaiwanAddress: { component: AddressField, inlineEditable: true } },
  displays: { TaiwanAddress: AddressDisplay },
  pages: import.meta.glob('./pages/**/*.tsx'),
});
```

`fields` and `displays` use the renderer's existing `createRegistry` types. Inline eligibility is
explicit per field; form-only registrations stay form-only. `FieldProps.errors` uses relative child
paths and `onChange` receives one semantic value in root forms, nested rows and picker drafts.
Precompiled mode retains unknown-component guidance; application React code requires the custom
frontend installation. Both install modes continue to be checked using actual published packages.

### Temporary source integration (maintainers)

`.ci/ui-integration.json` pins a reviewed 40-hex UI commit. The separately named source E2E job
checks out that exact commit with persisted credentials disabled, installs its frozen graph, builds
it and verifies all five linked package origins. `BRUME_UI_INTEGRATION=1` and `BRUME_UI_SOURCE` are
both required; modified checkouts and moving refs reject. Only source workbench acceptance enables
substitution. The published E2E job, precompiled frontend byte check and four install-smoke jobs
retain registry-origin checks and frozen package graphs. After actual alpha.13 publication, remove
the temporary source job/script/pin and enable composition tests in ordinary published E2E. Linux
baseline generation is opt-in; inspect every changed PNG and run ordinary non-update CI afterward.
