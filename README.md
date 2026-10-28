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
`config/brume.php` and requires a signed-in admin. A controller returns a page object:

```php
// routes/brume.php
use App\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('orders', OrderController::class)->name('brume.orders.index');
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

## Development

    composer install
    composer test      # Pest
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
- **訂單**: a Grid over 60 seeded orders (`workbench/database`): numeric amount, a status
  column rendered by the registered `OrderStatus` cell component, `created_at` formatted with
  `display()`, 15 per page.
- **元件展示**: every public brume-react component (buttons, inputs, Field, Card, EmptyState,
  Skeleton, DataTable + Pagination, Dialog, confirm, toasts, PageHeader, the charts; breadcrumbs
  come from the shell) in the real shell, as one registered component
  (`components/ComponentShowcase.tsx`). Its 圖表 section links to the dashboard.
- **系統 → 管理員**: the admins list with 36 seeded admins.
- Not in the menu, used by the e2e suite: `/admin/_demo/toast` (flashes the same toast every
  time) and `/admin/_demo/missing-component` (the guidance shown for an unregistered component).

The demo menu, routes and dashboard live in `workbench/` and are only active on the dev server,
not under Pest. `WORKBENCH_EMPTY_LIST=1 composer serve` empties the 管理員 list, to check its
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
running `composer serve` reads.

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
