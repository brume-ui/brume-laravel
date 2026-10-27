<?php

namespace Brume\Tests\Support;

use Brume\BrumeServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A throwaway Laravel project directory for installer tests, so nothing is written into the
 * repo or the Testbench skeleton. The application's base path points at it until cleanUp().
 */
final class ScratchProject
{
    private string $originalBasePath;

    public function __construct(private Application $app, public readonly string $path)
    {
        $this->originalBasePath = $app->basePath();
    }

    public static function create(Application $app): self
    {
        $path = sys_get_temp_dir().'/brume-scratch-'.bin2hex(random_bytes(6));

        foreach (['config', 'database/migrations', 'routes', 'public', 'resources/js', 'storage/framework/views'] as $dir) {
            File::ensureDirectoryExists($path.'/'.$dir);
        }

        File::put($path.'/.env', "APP_NAME=Scratch\nAPP_ENV=local\n");
        File::put($path.'/package.json', json_encode([
            '$schema' => 'https://json.schemastore.org/package.json',
            'private' => true,
            'type' => 'module',
            'scripts' => ['build' => 'vite build', 'dev' => 'vite'],
            'devDependencies' => ['axios' => '^1.11.0', 'vite' => '^7.0.4'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        File::put($path.'/config/database.php', "<?php\n\nreturn [];\n");

        $project = new self($app, $path);

        $app->setBasePath($path);
        // Publish destinations were resolved at boot; point them at the scratch project.
        $app->getProvider(BrumeServiceProvider::class)->registerPublishing();

        // Refuse to run if any path the installer writes to still points outside the scratch project.
        foreach ([$app->environmentFilePath(), $app->configPath(), $app->publicPath(), $app->databasePath(), $app->resourcePath()] as $resolved) {
            if (! str_starts_with($resolved, $path.'/')) {
                throw new RuntimeException("Scratch project path leaked: {$resolved}");
            }
        }

        return $project;
    }

    public function file(string $relative): string
    {
        return $this->path.'/'.$relative;
    }

    public function read(string $relative): string
    {
        return File::get($this->file($relative));
    }

    /** @return array<string, mixed> */
    public function json(string $relative): array
    {
        return json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $relative, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->file($relative)));
        File::put($this->file($relative), $contents);
    }

    public function cleanUp(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        $this->app->getProvider(BrumeServiceProvider::class)->registerPublishing();
        File::deleteDirectory($this->path);
    }
}
