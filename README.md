# brume/laravel

Brume admin panel for Laravel (Inertia + React). Work in progress (phase 1).

## Development

    composer install
    composer test      # Pest
    composer lint      # Pint
    composer serve     # workbench at http://127.0.0.1:8100/admin

The workbench uses SQLite and the session cookie `brume_workbench_session`, so it does not
clash with other local Laravel apps. It is a small demo admin (sign in as `admin` / `admin1234`):
a dashboard, 系統 → 管理員 with 36 seeded admins, and `/admin/_demo/toast`. The demo menu, routes
and dashboard live in `workbench/` and are only active on the dev server, not under Pest.

## End-to-end and install smoke tests

    vendor/bin/testbench vendor:publish --tag=brume-assets --force   # once, for composer serve
    pnpm --dir e2e exec playwright install chromium                  # once
    composer e2e      # Playwright + axe against the workbench on port 8100

`composer e2e` starts its own server (`e2e/serve.sh`: publish assets, fresh seeded database,
`display_errors=1` so PHP warnings printed into a response fail the suite). Stop `composer serve`
first; the suite never reuses a running server.

    export GITHUB_PACKAGES_TOKEN=$(gh auth token)   # vite mode only
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
`@herb09638/brume-*` version in `Brume\Version::UI`.

    export GITHUB_PACKAGES_TOKEN=$(gh auth token)   # needs read:packages
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
