<?php

namespace Brume\Console;

use Brume\Console\Concerns\ManagesAdmins;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use stdClass;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use ManagesAdmins;

    protected $signature = 'brume:install
        {--frontend : 由專案自行編譯前端（Vite），可以註冊自訂元件}
        {--precompiled : 使用 Brume 內附的預編譯前端（預設），可從 --frontend 切回來}
        {--username= : 第一個管理員的帳號}
        {--password= : 第一個管理員的密碼（至少 8 碼）}
        {--force : 覆蓋已存在的前端檔案，切回預編譯時不再詢問}';

    protected $description = '安裝 Brume：發布設定與路由、建立資料表和第一個管理員，並設定前端編譯方式';

    /** Overrides PHP_VERSION_ID in tests. */
    public static ?int $phpVersionId = null;

    public const NPMRC_LINES = [
        '@herb09638:registry=https://npm.pkg.github.com',
        '//npm.pkg.github.com/:_authToken=${GITHUB_PACKAGES_TOKEN}',
    ];

    public const SCRIPTS = [
        'brume:dev' => 'vite --config vite.brume.config.ts',
        'brume:build' => 'vite build --config vite.brume.config.ts',
    ];

    /** Stub file => destination relative to the project root. */
    private const FRONTEND_STUBS = [
        'app.tsx' => 'resources/js/brume/app.tsx',
        'components.ts' => 'resources/js/brume/components.ts',
        'brume.css' => 'resources/js/brume/brume.css',
        'vite.brume.config.ts' => 'vite.brume.config.ts',
    ];

    private const PACKAGE_ROOT = __DIR__.'/../..';

    public function handle(Filesystem $files): int
    {
        if ($this->option('frontend') && $this->option('precompiled')) {
            $this->message('error', '--frontend 和 --precompiled 只能選一個：要自訂元件就用 --frontend，否則用 --precompiled。');

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password !== null && ($error = $this->passwordError($password)) !== null) {
            $this->message('error', $error);

            return self::FAILURE;
        }

        $current = $this->currentMode($files);
        $mode = match (true) {
            $this->option('frontend') => 'vite',
            $this->option('precompiled') => 'precompiled',
            // Re-running the installer (e.g. after composer update) keeps the mode the project chose.
            default => $current,
        };

        if ($current === 'vite' && $mode === 'precompiled' && ! $this->confirmSwitchToPrecompiled($files)) {
            $this->message('warn', '已取消，沒有變更任何檔案。');

            return self::FAILURE;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'brume-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'brume-routes']);
        $this->components->task('發布 config/brume.php 與 routes/brume.php');

        $this->call('migrate', ['--force' => true]);

        if ($mode === 'vite') {
            $this->installFrontend($files);
        } else {
            // --force so a reinstall after composer update replaces outdated assets.
            $this->callSilently('vendor:publish', ['--tag' => 'brume-assets', '--force' => true]);
            $this->components->task('發布預編譯前端資源到 public/vendor/brume');
        }

        $this->writeEnv($files, 'BRUME_FRONTEND', $mode);
        $this->components->task("在 .env 設定 BRUME_FRONTEND={$mode}");

        $this->createFirstAdmin();
        $this->warnAboutMysqlSslConstant($files);

        $this->message('info', $mode === 'vite'
            ? 'Brume 已安裝，前端由專案自行編譯（--frontend）。'
            : 'Brume 已安裝，使用預編譯前端，不需要 Node 或 Vite。');

        if ($mode === 'vite') {
            $this->printFrontendNextSteps();
        }

        return self::SUCCESS;
    }

    protected function phpVersionId(): int
    {
        return static::$phpVersionId ?? PHP_VERSION_ID;
    }

    private function currentMode(Filesystem $files): string
    {
        $env = $this->laravel->environmentFilePath();

        if ($files->exists($env) && preg_match('/^BRUME_FRONTEND=["\']?(\w+)/m', $files->get($env), $match)) {
            return $match[1] === 'vite' ? 'vite' : 'precompiled';
        }

        return config('brume.frontend.mode') === 'vite' ? 'vite' : 'precompiled';
    }

    private function confirmSwitchToPrecompiled(Filesystem $files): bool
    {
        $count = $this->countRegisteredComponents($files);

        if ($count === 0) {
            return true;
        }

        $this->message('warn', "目前註冊了 {$count} 個自訂元件，切回預編譯後這些元件會顯示錯誤訊息。");

        if ($this->option('force')) {
            return true;
        }

        return confirm(
            label: '要切回預編譯嗎？',
            default: false,
            hint: '想保留自訂元件就選 No，繼續用 --frontend。',
        );
    }

    /**
     * Heuristic: strip comments, take the `export const components … = { … }` object literal in
     * resources/js/brume/components.ts and count its comma-separated entries (`Foo`, `Foo: Bar`
     * and `...more` each count as one).
     */
    private function countRegisteredComponents(Filesystem $files): int
    {
        $path = resource_path('js/brume/components.ts');

        if (! $files->exists($path)) {
            return 0;
        }

        $source = preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $files->get($path));

        if (! preg_match('/export\s+const\s+components\b[^=]*=\s*\{([^}]*)\}/', $source, $match)) {
            return 0;
        }

        return count(array_filter(array_map('trim', explode(',', $match[1])), fn (string $entry) => $entry !== ''));
    }

    private function installFrontend(Filesystem $files): void
    {
        foreach (self::FRONTEND_STUBS as $stub => $destination) {
            $target = base_path($destination);

            if ($files->exists($target) && ! $this->option('force')) {
                $this->components->twoColumnDetail($destination, '已存在，保留不動（加 --force 才會覆蓋）');

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy(self::PACKAGE_ROOT.'/stubs/frontend/'.$stub, $target);
            $this->components->twoColumnDetail($destination, '已建立');
        }

        $this->updatePackageJson($files);
        $this->components->task('在 package.json 加入 @herb09638/brume-* 依賴與 brume:dev、brume:build');

        $this->updateNpmrc($files);
        $this->components->task('在 .npmrc 設定 GitHub Packages 的 registry');
    }

    /**
     * Adds the packages and versions the precompiled build uses (frontend/package.json is the
     * single source of truth), never downgrading a package the project already has.
     */
    private function updatePackageJson(Filesystem $files): void
    {
        $path = base_path('package.json');
        $package = $files->exists($path)
            ? json_decode($files->get($path), false, 512, JSON_THROW_ON_ERROR)
            : (object) ['private' => true, 'type' => 'module'];

        $ours = json_decode($files->get(self::PACKAGE_ROOT.'/frontend/package.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ($ours[$section] ?? [] as $name => $version) {
                $this->mergeDependency($package, $section, $name, $version);
            }
        }

        $package->scripts ??= new stdClass;

        foreach (self::SCRIPTS as $name => $command) {
            $package->scripts->{$name} ??= $command;
        }

        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // npm writes package.json with 2-space indentation.
        $json = preg_replace_callback('/^( {4})+/m', fn (array $m) => str_repeat('  ', strlen($m[0]) / 4), $json);

        $files->put($path, $json."\n");
    }

    private function mergeDependency(stdClass $package, string $section, string $name, string $version): void
    {
        foreach (['dependencies', 'devDependencies'] as $existingSection) {
            $existing = $package->{$existingSection}->{$name} ?? null;

            if ($existing === null) {
                continue;
            }

            $parsed = $this->parseSpec($existing);

            if ($parsed === null) {
                // Tags, workspace:, git urls … are the project's call.
                $this->components->twoColumnDetail($name, "專案已有 {$existing}，保留不變（Brume 使用 {$version}）");
            } elseif (version_compare($parsed['version'], $version, '>')) {
                $this->components->twoColumnDetail($name, "專案已有 {$existing}，比 Brume 使用的 {$version} 新，保留不變");
            } elseif (! $this->rangeIncludes($parsed, $version)) {
                $this->components->twoColumnDetail($name, "從 {$existing} 更新為 Brume 需要的 {$version}");
                $package->{$existingSection}->{$name} = $version;
            }

            return;
        }

        $package->{$section} ??= new stdClass;
        $package->{$section}->{$name} = $version;
        $package->{$section} = $this->sortKeys($package->{$section});
    }

    /**
     * Whether a ^ / ~ / >= / exact spec whose base version is not newer than ours already admits ours.
     *
     * @param  array{operator: string, version: string}  $spec
     */
    private function rangeIncludes(array $spec, string $ours): bool
    {
        [$major, $minor] = array_map('intval', explode('.', $spec['version'].'.0.0'));
        [$ourMajor, $ourMinor] = array_map('intval', explode('.', $ours));

        return match ($spec['operator']) {
            '>=' => true,
            '^' => $major === $ourMajor && ($major > 0 || $minor === $ourMinor),
            '~' => $major === $ourMajor && $minor === $ourMinor,
            default => $spec['version'] === $ours,
        };
    }

    /** @return array{operator: string, version: string}|null */
    private function parseSpec(string $spec): ?array
    {
        if (! preg_match('/^\s*(\^|~|>=)?\s*v?(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)\s*$/', $spec, $match)) {
            return null;
        }

        return ['operator' => $match[1], 'version' => $match[2]];
    }

    private function sortKeys(stdClass $object): stdClass
    {
        $array = (array) $object;
        ksort($array);

        return (object) $array;
    }

    private function updateNpmrc(Filesystem $files): void
    {
        $path = base_path('.npmrc');
        $contents = $files->exists($path) ? $files->get($path) : '';
        $existing = array_map('trim', explode("\n", $contents));

        foreach (self::NPMRC_LINES as $line) {
            if (! in_array($line, $existing, true)) {
                $contents = $this->appendLine($contents, $line);
            }
        }

        $files->put($path, $contents);
    }

    private function writeEnv(Filesystem $files, string $key, string $value): void
    {
        $path = $this->laravel->environmentFilePath();
        $contents = $files->exists($path) ? $files->get($path) : '';
        $line = "{$key}={$value}";

        $contents = preg_match("/^{$key}=.*$/m", $contents)
            ? preg_replace("/^{$key}=.*$/m", $line, $contents)
            : $this->appendLine($contents, $line);

        $files->put($path, $contents);
    }

    private function appendLine(string $contents, string $line): string
    {
        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return $contents.$line."\n";
    }

    private function createFirstAdmin(): void
    {
        $model = $this->userModel();

        if ($model::query()->exists()) {
            $this->components->twoColumnDetail('第一個管理員', '已有管理員，略過；要再新增請用 php artisan brume:user');

            return;
        }

        $username = $this->option('username') ?? text(
            label: '管理員帳號',
            default: 'admin',
            required: true,
        );

        $model::query()->create([
            'username' => $username,
            'name' => $username,
            'password' => $this->option('password') ?? $this->askNewPassword('管理員密碼（至少 8 碼）'),
        ]);

        $this->components->task("建立管理員「{$username}」");
    }

    /**
     * PHP 8.5 deprecates PDO::MYSQL_ATTR_SSL_CA. The deprecation notice is printed into the
     * response and breaks Inertia's JSON on page visits (spec §11).
     */
    private function warnAboutMysqlSslConstant(Filesystem $files): void
    {
        $path = config_path('database.php');

        if ($this->phpVersionId() < 80500 || ! $files->exists($path)) {
            return;
        }

        $config = $files->get($path);

        // Already fixed configs mention Pdo\Mysql::ATTR_SSL_CA (or Mysql::ATTR_SSL_CA after a use statement).
        if (! str_contains($config, 'PDO::MYSQL_ATTR_SSL_CA') || str_contains($config, 'Mysql::ATTR_SSL_CA')) {
            return;
        }

        $this->message('warn', 'config/database.php 使用了 PDO::MYSQL_ATTR_SSL_CA，這個常數在 PHP 8.5 起已棄用。');
        $this->line('  棄用警告會被印進 HTTP 回應：第一次整頁載入看不出來，但 Brume 換頁時的 JSON 會壞掉。');
        $this->line('  請把它改成依 PHP 版本選擇常數：');
        $this->line("  (PHP_VERSION_ID >= 80500 ? \\Pdo\\Mysql::ATTR_SSL_CA : \\PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),");
        $this->newLine();
    }

    private function printFrontendNextSteps(): void
    {
        $this->line('  下一步：');
        $this->line('  1. 設定環境變數 GITHUB_PACKAGES_TOKEN：一個有 read:packages 權限的 GitHub token。');
        $this->line('     本機與 CI 都要設定，.npmrc 用它從 GitHub Packages 下載 @herb09638 套件。');
        $this->line('  2. pnpm install（或 npm install）');
        $this->line('  3. pnpm brume:dev 啟動開發伺服器；部署前執行 pnpm brume:build');
        $this->newLine();
    }
}
