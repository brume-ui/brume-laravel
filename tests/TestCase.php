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
        // Test-only tables, migrated with the workbench's by RefreshDatabase.
        $app->afterResolving('migrator', fn (Migrator $migrator) => $migrator->path(__DIR__.'/database/migrations'));
    }
}
