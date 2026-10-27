# brume/laravel

Brume admin panel for Laravel (Inertia + React). Work in progress (phase 1).

## Development

    composer install
    composer test      # Pest
    composer lint      # Pint
    composer serve     # workbench at http://127.0.0.1:8100/admin

The workbench uses SQLite and the session cookie `brume_workbench_session`, so it does not
clash with other local Laravel apps.
