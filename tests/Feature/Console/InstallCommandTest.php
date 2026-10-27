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

it('adds every missing package at the version frontend/package.json uses', function () use ($packageRoot) {
    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->assertSuccessful();

    $ours = json_decode(file_get_contents($packageRoot.'/frontend/package.json'), true);
    $host = $this->project->json('package.json');

    foreach (['dependencies', 'devDependencies'] as $section) {
        foreach ($ours[$section] as $name => $version) {
            if ($name !== 'vite') {
                expect($host[$section][$name] ?? null)->toBe($version, $name);
            }
        }
    }

    // The project's own specs are untouched, even where Brume pins an exact version.
    expect($host['devDependencies']['vite'])->toBe('^8.0.0')
        ->and($host['devDependencies']['axios'])->toBe('^1.11.0');
});

it('reports incompatible dependencies, changes none of them and fails after setting up the rest', function () {
    $package = $this->project->json('package.json');
    $package['dependencies'] = ['react' => '^18.3.1', 'lodash' => '^4.17.21'];
    $package['devDependencies']['vite'] = '^7.0.4';
    $package['devDependencies']['tailwindcss'] = '^3.4.17';
    $this->project->put('package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $before = $this->project->json('package.json');

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('package.json 裡有 3 個套件的版本和 Brume 不相容')
        ->expectsTable(['套件', '專案目前', 'Brume 需要', '怎麼處理'], [
            ['react', '^18.3.1', '19.3.1', '把版本改成 ^19.3.1，或加 --upgrade-deps 重跑'],
            ['tailwindcss', '^3.4.17', '4.3.3', '把版本改成 ^4.3.3，或加 --upgrade-deps 重跑'],
            ['vite', '^7.0.4', '8.3.5', '把版本改成 ^8.3.5，或加 --upgrade-deps 重跑'],
        ])
        ->assertFailed();

    $host = $this->project->json('package.json');

    foreach (['react', 'lodash'] as $name) {
        expect($host['dependencies'][$name])->toBe($before['dependencies'][$name]);
    }

    expect($host['devDependencies']['vite'])->toBe('^7.0.4')
        ->and($host['devDependencies']['tailwindcss'])->toBe('^3.4.17')
        ->and($host['dependencies']['@herb09638/brume-react'])->toBe(Version::UI)
        ->and($this->project->file('resources/js/brume/app.tsx'))->toBeFile()
        ->and(envLines($this->project, 'BRUME_FRONTEND'))->toBe(['BRUME_FRONTEND=vite'])
        ->and(User::query()->count())->toBe(1);
});

it('explains why a conflicting vite or laravel-vite-plugin cannot be kept', function () {
    $package = $this->project->json('package.json');
    $package['devDependencies']['vite'] = '^7.0.7';
    $package['devDependencies']['laravel-vite-plugin'] = '^2.0.0';
    $this->project->put('package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('laravel-vite-plugin：laravel-vite-plugin 2 不支援 vite 8，Brume 需要 3.x。')
        ->expectsOutputToContain('vite：Brume 的 @vitejs/plugin-react 6 與 laravel-vite-plugin 3 只支援 vite 8')
        ->assertFailed();
});

it('raises older dependencies keeping their operator with --upgrade-deps but never downgrades', function () {
    $package = $this->project->json('package.json');
    $package['dependencies'] = ['react' => '~18.3.1', 'react-dom' => '^20.0.0'];
    $package['devDependencies']['vite'] = '^7.0.4';
    $this->project->put('package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $this->artisan('brume:install', ['--frontend' => true, '--upgrade-deps' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('package.json 裡有 1 個套件的版本和 Brume 不相容')
        ->assertSuccessful();

    $host = $this->project->json('package.json');

    expect($host['dependencies']['react'])->toBe('~19.3.1')
        ->and($host['devDependencies']['vite'])->toBe('^8.3.5')
        ->and($host['dependencies']['react-dom'])->toBe('^20.0.0');
});

it('does not raise dependencies with --force alone, and --upgrade-deps keeps project files', function () {
    $package = $this->project->json('package.json');
    $package['devDependencies']['vite'] = '^7.0.4';
    $this->project->put('package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $this->project->put('resources/js/brume/components.ts', '// my components');

    $this->artisan('brume:install', ['--frontend' => true, '--force' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->expectsOutputToContain('也可以加 --upgrade-deps 重跑')
        ->assertFailed();

    expect($this->project->json('package.json')['devDependencies']['vite'])->toBe('^7.0.4');

    $this->project->put('resources/js/brume/components.ts', '// my components');

    $this->artisan('brume:install', ['--frontend' => true, '--upgrade-deps' => true])
        ->doesntExpectOutputToContain('不相容')
        ->assertSuccessful();

    expect($this->project->json('package.json')['devDependencies']['vite'])->toBe('^8.3.5')
        ->and($this->project->read('resources/js/brume/components.ts'))->toBe('// my components');
});

it('is idempotent for .npmrc, .env, .gitignore and package.json', function () {
    $this->project->put('.npmrc', "engine-strict=true\n");
    $this->project->put('.env', "APP_NAME=Scratch\nBRUME_FRONTEND=precompiled\nAPP_DEBUG=true\n");
    $this->project->put('.gitignore', "/node_modules\n/public/build");

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->assertSuccessful();

    $first = array_map(fn (string $file) => $this->project->read($file), ['package.json', '.npmrc', '.env', '.gitignore']);

    $this->artisan('brume:install', ['--frontend' => true])->assertSuccessful();

    expect(array_map(fn (string $file) => $this->project->read($file), ['package.json', '.npmrc', '.env', '.gitignore']))->toBe($first)
        ->and($this->project->read('.npmrc'))->toBe(implode("\n", [
            'engine-strict=true',
            '@herb09638:registry=https://npm.pkg.github.com',
            '//npm.pkg.github.com/:_authToken=${GITHUB_PACKAGES_TOKEN}',
        ])."\n")
        ->and($this->project->read('.env'))->toBe("APP_NAME=Scratch\nBRUME_FRONTEND=vite\nAPP_DEBUG=true\n")
        ->and($this->project->read('.gitignore'))->toBe("/node_modules\n/public/build\n/public/build-brume\n/public/brume.hot\n")
        ->and(array_keys($this->project->json('package.json')['scripts']))->toBe(['build', 'dev', 'brume:dev', 'brume:build']);
});

it('does not create a .gitignore the project does not have', function () {
    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->assertSuccessful();

    expect($this->project->file('.gitignore'))->not->toBeFile();
});

it('keeps the package.json indentation and key order of the project', function (string $indent) {
    $original = str_replace('    ', $indent, <<<'JSON'
        {
            "name": "shop",
            "devDependencies": {
                "vite": "^8.0.0",
                "axios": "^1.11.0"
            },
            "scripts": {
                "dev": "vite"
            }
        }
        JSON);
    $this->project->put('package.json', $original);

    $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
        ->assertSuccessful();

    $written = $this->project->read('package.json');

    expect($written)->toStartWith(str_replace('    ', $indent, "{\n    \"name\": \"shop\",\n    \"devDependencies\": {\n        \"vite\": \"^8.0.0\",\n        \"axios\": \"^1.11.0\",\n        \"@tailwindcss/vite\""))
        ->and($written)->not->toEndWith("\n")
        ->and(array_keys(json_decode($written, true)))->toBe(['name', 'devDependencies', 'scripts', 'dependencies']);
})->with([
    'two spaces' => '  ',
    'four spaces' => '    ',
    'tabs' => "\t",
]);

it('fails before writing anything when package.json cannot be used', function (?string $packageJson, string $message, bool $breakFrontend = false) {
    if ($packageJson !== null) {
        $this->project->put('package.json', $packageJson);
    }

    $frontendPackagePath = InstallCommand::$frontendPackagePath;

    if ($breakFrontend) {
        InstallCommand::$frontendPackagePath = $this->project->file('missing/package.json');
    }

    $env = $this->project->read('.env');

    try {
        $this->artisan('brume:install', ['--frontend' => true, '--username' => 'root', '--password' => 'secret-password'])
            ->expectsOutputToContain($message)
            ->assertFailed();
    } finally {
        InstallCommand::$frontendPackagePath = $frontendPackagePath;
    }

    expect($this->project->file('config/brume.php'))->not->toBeFile()
        ->and($this->project->file('routes/brume.php'))->not->toBeFile()
        ->and($this->project->file('resources/js/brume'))->not->toBeDirectory()
        ->and($this->project->file('.npmrc'))->not->toBeFile()
        ->and($this->project->read('.env'))->toBe($env)
        ->and(User::query()->count())->toBe(0);

    if ($packageJson !== null) {
        expect($this->project->read('package.json'))->toBe($packageJson);
    }
})->with([
    'invalid JSON' => ["{\n  \"name\": \"shop\",\n}\n", 'package.json 不是有效的 JSON'],
    'not an object' => ["[\"shop\"]\n", 'package.json 的最外層必須是物件'],
    'dependencies not an object' => ["{\"dependencies\": [\"react\"]}\n", 'package.json 的 "dependencies" 必須是物件'],
    'missing frontend/package.json' => [null, '找不到 Brume 的 frontend/package.json', true],
]);

it('installs without an admin when not interactive and no credentials are given', function () {
    $this->artisan('brume:install', ['--no-interaction' => true])
        ->expectsOutputToContain('請加上 --username 與 --password，或之後執行 php artisan brume:user 建立管理員。')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and($this->project->file('config/brume.php'))->toBeFile();
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
