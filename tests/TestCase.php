<?php

namespace Brume\Tests;

use Brume\BrumeServiceProvider;
use Brume\Support\Assets;
use Illuminate\Database\Migrations\Migrator;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        Assets::$manifestPath = __DIR__.'/fixtures/manifest.json';
    }

    protected function getPackageProviders($app): array
    {
        return [BrumeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        // Brume's Inertia pages live in the brume-ui packages, not in this repo.
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        // BRUME_TEST_DB=mysql runs the same suite on MySQL (the CI mysql job); SQLite otherwise.
        if (getenv('BRUME_TEST_DB') === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', [
                ...$app['config']->get('database.connections.mysql'),
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'brume_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
            ]);
        }
        // Test-only tables, migrated with the workbench's by RefreshDatabase.
        $app->afterResolving('migrator', fn (Migrator $migrator) => $migrator->path(__DIR__.'/database/migrations'));
    }
}
