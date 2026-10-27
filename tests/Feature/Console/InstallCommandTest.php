<?php

use Brume\Console\InstallCommand;
use Brume\Models\User;
use Brume\Tests\Support\ScratchProject;
use Brume\Version;
use Illuminate\Support\Facades\Hash;

$packageRoot = dirname(__DIR__, 3);

beforeEach(function () {
    $this->project = ScratchProject::create($this->app);
    // Default to a PHP version without the 8.5 deprecation unless a test says otherwise.
    InstallCommand::$phpVersionId = 80400;
});

afterEach(function () {
    InstallCommand::$phpVersionId = null;
    $this->project->cleanUp();
});

function envLines(ScratchProject $project, string $key): array
{
    return array_values(array_filter(
        explode("\n", $project->read('.env')),
        fn (string $line) => str_starts_with($line, $key.'='),
    ));
}

function registerComponents(ScratchProject $project): void
{
    $project->put('resources/js/brume/components.ts', <<<'TS'
        import type { ComponentType } from 'react';
        import { StockBadge } from './components/StockBadge';
        import { OrderMap } from './components/OrderMap';

        // export const components = { Commented };
        export const components: Record<string, ComponentType<any>> = {
          StockBadge,
          Map: OrderMap, /* inline comment, { braces } */
        };
        TS);
}

it('installs in precompiled mode by default', function () use ($packageRoot) {
    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('預編譯')
        ->assertSuccessful();

    expect($this->project->file('config/brume.php'))->toBeFile()
        ->and($this->project->read('routes/brume.php'))->toBe(file_get_contents($packageRoot.'/stubs/routes/brume.php'))
        ->and($this->project->read('public/vendor/brume/.vite/manifest.json'))
        ->toBe(file_get_contents($packageRoot.'/dist/.vite/manifest.json'))
        ->and(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=precompiled'])
        ->and($this->project->file('resources/js/brume/app.tsx'))->not->toBeFile()
        ->and($this->project->file('.npmrc'))->not->toBeFile();

    $user = User::query()->sole();

    expect($user->username)->toBe('root')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('refreshes published assets on reinstall but keeps an edited config and routes file', function () {
    $this->project->put('config/brume.php', "<?php\n\nreturn ['name' => 'Mine'];\n");
    $this->project->put('routes/brume.php', "<?php\n\n// mine\n");
    $this->project->put('public/vendor/brume/.vite/manifest.json', '{"stale":true}');

    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])->assertSuccessful();

    expect($this->project->read('config/brume.php'))->toContain("'Mine'")
        ->and($this->project->read('routes/brume.php'))->toContain('// mine')
        ->and($this->project->read('public/vendor/brume/.vite/manifest.json'))->not->toContain('stale');
});

it('asks for the first admin when no options are given', function () {
    $this->artisan('brume:install')
        ->expectsQuestion('管理員帳號', 'root')
        ->expectsQuestion('管理員密碼（至少 8 碼）', 'secret-password')
        ->expectsQuestion('再輸入一次密碼', 'secret-password')
        ->assertSuccessful();

    expect(User::query()->sole()->username)->toBe('root');
});

it('rejects a first admin password shorter than 8 characters', function () {
    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'short'])
        ->expectsOutputToContain('至少 8 碼')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('skips creating an admin when one already exists', function () {
    brumeUser();

    $this->artisan('brume:install', ['--no-interaction' => true])
        ->expectsOutputToContain('已有管理員')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('scaffolds the project frontend with --frontend', function () use ($packageRoot) {
    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('GITHUB_PACKAGES_TOKEN：一個有 read:packages 權限的 GitHub token')
        ->expectsOutputToContain('pnpm install')
        ->expectsOutputToContain('pnpm brume:dev')
        ->assertSuccessful();

    foreach (['app.tsx', 'components.ts', 'brume.css'] as $file) {
        expect($this->project->read("resources/js/brume/{$file}"))
            ->toBe(file_get_contents($packageRoot."/stubs/frontend/{$file}"));
    }

    expect($this->project->read('vite.brume.config.ts'))
        ->toBe(file_get_contents($packageRoot.'/stubs/frontend/vite.brume.config.ts'))
        ->and(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=vite'])
        ->and($this->project->file('public/vendor/brume'))->not->toBeDirectory();

    $package = $this->project->json('package.json');

    foreach (['tokens', 'schema', 'react', 'renderer', 'inertia'] as $name) {
        expect($package['dependencies']["@herb09638/brume-{$name}"])->toBe(Version::UI);
    }

    expect($package['scripts'])->toMatchArray([
        'brume:dev' => 'vite --config vite.brume.config.ts',
        'brume:build' => 'vite build --config vite.brume.config.ts',
        'dev' => 'vite',
    ]);
});

it('adds the same dependency versions as frontend/package.json without downgrading the project', function () use ($packageRoot) {
    $package = $this->project->json('package.json');
    $package['dependencies'] = ['react' => '^20.1.0', 'lodash' => '^4.17.21'];
    $this->project->put('package.json', json_encode($package));

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('專案已有 ^20.1.0')
        ->assertSuccessful();

    $ours = json_decode(file_get_contents($packageRoot.'/frontend/package.json'), true);
    $host = $this->project->json('package.json');

    $expected = [...$ours['dependencies'], ...$ours['devDependencies']];
    $actual = [...$host['dependencies'], ...$host['devDependencies']];

    // Every package Brume needs, at the version the precompiled build uses...
    foreach ($expected as $name => $version) {
        expect($actual)->toHaveKey($name);

        if ($name !== 'react') {
            expect($actual[$name])->toBe($version, $name);
        }
    }

    // ...except a newer version the project already had, and its own packages untouched.
    expect($host['dependencies']['react'])->toBe('^20.1.0')
        ->and($host['dependencies']['lodash'])->toBe('^4.17.21')
        ->and($host['devDependencies']['axios'])->toBe('^1.11.0')
        // The project had vite ^7, older than Brume needs, so it is raised.
        ->and($host['devDependencies']['vite'])->toBe($ours['devDependencies']['vite']);
});

it('is idempotent for .npmrc, .env and package.json', function () {
    $this->project->put('.npmrc', "engine-strict=true\n");
    $this->project->put('.env', "APP_NAME=Scratch\nBRUME_FRONTEND=precompiled\nAPP_DEBUG=true\n");

    foreach ([1, 2] as $run) {
        $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
            ->assertSuccessful();
    }

    expect($this->project->read('.npmrc'))->toBe(implode("\n", [
        'engine-strict=true',
        '@herb09638:registry=https://npm.pkg.github.com',
        '//npm.pkg.github.com/:_authToken=${GITHUB_PACKAGES_TOKEN}',
    ])."\n")
        ->and($this->project->read('.env'))->toBe("APP_NAME=Scratch\nBRUME_FRONTEND=vite\nAPP_DEBUG=true\n");

    $package = $this->project->json('package.json');

    expect(array_keys($package['scripts']))->toBe(['build', 'dev', 'brume:dev', 'brume:build']);
});

it('adds BRUME_FRONTEND to a .env without one and creates a missing .env', function () {
    $this->project->put('.env', 'APP_NAME=Scratch');

    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])->assertSuccessful();

    expect($this->project->read('.env'))->toBe("APP_NAME=Scratch\nBRUME_FRONTEND=precompiled\n");

    unlink($this->project->file('.env'));

    $this->artisan('brume:install', ['--frontend' => true])->assertSuccessful();

    expect($this->project->read('.env'))->toBe("BRUME_FRONTEND=vite\n");
});

it('does not overwrite existing frontend files unless --force', function () use ($packageRoot) {
    $this->project->put('resources/js/brume/app.tsx', '// customised');
    $this->project->put('vite.brume.config.ts', '// customised');

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('resources/js/brume/app.tsx')
        ->assertSuccessful();

    expect($this->project->read('resources/js/brume/app.tsx'))->toBe('// customised')
        ->and($this->project->read('vite.brume.config.ts'))->toBe('// customised')
        ->and($this->project->file('resources/js/brume/components.ts'))->toBeFile();

    $this->artisan('brume:install', ['--frontend' => true, '--force' => true])->assertSuccessful();

    expect($this->project->read('resources/js/brume/app.tsx'))
        ->toBe(file_get_contents($packageRoot.'/stubs/frontend/app.tsx'));
});

it('keeps vite mode when reinstalled without a mode option', function () {
    $this->project->put('.env', "BRUME_FRONTEND=vite\n");

    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])->assertSuccessful();

    expect(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=vite'])
        ->and($this->project->file('resources/js/brume/app.tsx'))->toBeFile();
});

it('warns and asks before switching back to precompiled with registered components', function () {
    $this->project->put('.env', "BRUME_FRONTEND=vite\n");
    registerComponents($this->project);

    $this->artisan('brume:install', ['--precompiled' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('目前註冊了 2 個自訂元件，切回預編譯後這些元件會顯示錯誤訊息。')
        ->expectsConfirmation('要切回預編譯嗎？', 'yes')
        ->assertSuccessful();

    expect(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=precompiled'])
        ->and($this->project->file('public/vendor/brume/.vite/manifest.json'))->toBeFile();
});

it('changes nothing when switching back is declined', function () {
    $this->project->put('.env', "BRUME_FRONTEND=vite\n");
    registerComponents($this->project);

    $this->artisan('brume:install', ['--precompiled' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsConfirmation('要切回預編譯嗎？', 'no')
        ->assertFailed();

    expect(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=vite'])
        ->and($this->project->file('config/brume.php'))->not->toBeFile()
        ->and(User::query()->count())->toBe(0);
});

it('switches back without asking when forced or when no components are registered', function () {
    $this->project->put('.env', "BRUME_FRONTEND=vite\n");
    registerComponents($this->project);

    $this->artisan('brume:install', ['--precompiled' => true, '--force' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('目前註冊了 2 個自訂元件')
        ->assertSuccessful();

    expect(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=precompiled']);

    $this->project->put('.env', "BRUME_FRONTEND=vite\n");
    $this->project->put('resources/js/brume/components.ts', file_get_contents(dirname(__DIR__, 3).'/stubs/frontend/components.ts'));

    $this->artisan('brume:install', ['--precompiled' => true])
        ->doesntExpectOutputToContain('自訂元件')
        ->assertSuccessful();

    expect(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=precompiled']);
});

it('refuses --frontend together with --precompiled', function () {
    $this->artisan('brume:install', ['--frontend' => true, '--precompiled' => true])->assertFailed();

    expect($this->project->file('config/brume.php'))->not->toBeFile();
});

it('warns about PDO::MYSQL_ATTR_SSL_CA on PHP 8.5 and later', function () {
    $this->project->put('config/database.php', "<?php\n\nreturn ['options' => [PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')]];\n");
    InstallCommand::$phpVersionId = 80500;

    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('config/database.php 使用了 PDO::MYSQL_ATTR_SSL_CA，這個常數在 PHP 8.5 起已棄用。')
        ->expectsOutputToContain('Brume 換頁時的 JSON 會壞掉')
        ->expectsOutputToContain('(PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA)')
        ->assertSuccessful();
});

it('does not warn about PDO::MYSQL_ATTR_SSL_CA before PHP 8.5 or once fixed', function () {
    $this->project->put('config/database.php', "<?php\n\nreturn ['options' => [PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')]];\n");

    $this->artisan('brume:install', ['--username' => 'root', '--password' => 'secret-password'])
        ->doesntExpectOutputToContain('MYSQL_ATTR_SSL_CA')
        ->assertSuccessful();

    InstallCommand::$phpVersionId = 80500;
    $this->project->put('config/database.php', "<?php\n\nreturn ['options' => [(PHP_VERSION_ID >= 80500 ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA')]];\n");

    $this->artisan('brume:install')
        ->doesntExpectOutputToContain('MYSQL_ATTR_SSL_CA')
        ->assertSuccessful();
});

it('ships a brume.css stub with Tailwind theme and utilities but no preflight', function () use ($packageRoot) {
    $css = file_get_contents($packageRoot.'/stubs/frontend/brume.css');

    expect($css)->toContain('@import "tailwindcss/theme.css" layer(theme);')
        ->toContain('@import "tailwindcss/utilities.css" layer(utilities)')
        ->toContain('@import "@herb09638/brume-tokens/theme.css";')
        ->toContain('@source "./";')
        ->not->toContain('tailwindcss/preflight.css')
        ->not->toContain('@import "tailwindcss";')
        ->and(file_get_contents($packageRoot.'/stubs/frontend/vite.brume.config.ts'))
        ->toContain("import tailwindcss from '@tailwindcss/vite';")
        ->toContain("hotFile: 'public/brume.hot'")
        ->toContain("buildDirectory: 'build-brume'");
});
