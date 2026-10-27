# brume/laravel

Brume admin panel for Laravel (Inertia + React). Work in progress (phase 1).

## Development

    composer install
    composer test      # Pest
    composer lint      # Pint
    composer serve     # workbench at http://127.0.0.1:8100/admin

The workbench uses SQLite and the session cookie `brume_workbench_session`, so it does not
clash with other local Laravel apps.

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
