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
downgrades). Without it the installer stops and lists the conflicts.

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
        $grid->column('created_at', '建立時間');

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

The workbench uses SQLite and the session cookie `brume_workbench_session`, so it does not
clash with other local Laravel apps. It is a small demo admin (sign in as `admin` / `admin1234`):
a dashboard, 系統 → 管理員 with 36 seeded admins, and `/admin/_demo/toast`. The demo menu, routes
and dashboard live in `workbench/` and are only active on the dev server, not under Pest.
`WORKBENCH_EMPTY_LIST=1 composer serve` empties the 管理員 list, to check its empty state.

## End-to-end and install smoke tests

    vendor/bin/testbench vendor:publish --tag=brume-assets --force   # once, for composer serve
    pnpm --dir e2e exec playwright install chromium                  # once
    composer e2e      # Playwright + axe against the workbench on port 8100

`composer e2e` starts its own server (`e2e/serve.sh`: publish assets, fresh seeded database,
`display_errors=1` so PHP warnings printed into a response fail the suite). Stop `composer serve`
first; the suite never reuses a running server.

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
