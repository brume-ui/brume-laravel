<?php

namespace Brume\Console;

use Brume\Console\Concerns\ManagesAdmins;
use Brume\Console\Support\MergeResult;
use Brume\Console\Support\PackageJsonFile;
use Brume\Console\Support\PackageJsonMerger;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
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
        {--force : 覆蓋已存在的前端檔案，切回預編譯時不再詢問}
        {--upgrade-deps : 把 package.json 裡太舊的依賴升到 Brume 需要的版本（保留 ^ 或 ~，不會降版）}';

    protected $description = '安裝 Brume：發布設定與路由、建立資料表和第一個管理員，並設定前端編譯方式';

    /** Overrides PHP_VERSION_ID in tests. */
    public static ?int $phpVersionId = null;

    /** Brume's own frontend manifest: the single source of the versions --frontend adds. */
    public static string $frontendPackagePath = __DIR__.'/../../frontend/package.json';

    public const GITIGNORE_LINES = [
        '/public/build-brume',
        '/public/brume.hot',
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

        if ($mode === 'vite' && ($error = $this->frontendPreflightError($files)) !== null) {
            $this->message('error', $error);

            return self::FAILURE;
        }

        if ($current === 'vite' && $mode === 'precompiled' && ! $this->confirmSwitchToPrecompiled($files)) {
            $this->message('warn', '已取消，沒有變更任何檔案。');

            return self::FAILURE;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'brume-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'brume-routes']);
        $this->components->task('發布 config/brume.php 與 routes/brume.php');

        $this->call('migrate', ['--force' => true]);

        $merge = new MergeResult;

        if ($mode === 'vite') {
            $merge = $this->installFrontend($files);
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

        return $this->reportDependencyConflicts($merge) ? self::SUCCESS : self::FAILURE;
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

    /**
     * Everything --frontend reads must be usable before anything is written, so a broken
     * package.json never leaves a half-installed project.
     */
    private function frontendPreflightError(Filesystem $files): ?string
    {
        if (! is_readable(self::$frontendPackagePath)) {
            return '找不到 Brume 的 frontend/package.json（'.self::$frontendPackagePath.'），請重新安裝 brume/laravel。';
        }

        try {
            PackageJsonFile::parse($files->get(self::$frontendPackagePath), 'Brume 的 frontend/package.json');

            if ($files->exists($path = base_path('package.json'))) {
                PackageJsonFile::parse($files->get($path));
            }
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function installFrontend(Filesystem $files): MergeResult
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

        $merge = $this->updatePackageJson($files);
        $this->components->task('在 package.json 加入 @brume-ui/brume-* 依賴與 brume:dev、brume:build');

        if ($files->exists(base_path('.gitignore'))) {
            $this->appendMissingLines($files, base_path('.gitignore'), self::GITIGNORE_LINES);
            $this->components->task('在 .gitignore 忽略 public/build-brume 與 public/brume.hot');
        }

        return $merge;
    }

    /**
     * Adds missing packages (frontend/package.json is the single source of truth) and scripts.
     * Existing specs are only changed with --upgrade-deps; see PackageJsonMerger.
     */
    private function updatePackageJson(Filesystem $files): MergeResult
    {
        $path = base_path('package.json');
        $original = $files->exists($path) ? $files->get($path) : null;
        $package = $original !== null ? PackageJsonFile::parse($original) : (object) ['private' => true, 'type' => 'module'];
        $ours = json_decode($files->get(self::$frontendPackagePath), true, 512, JSON_THROW_ON_ERROR);
        $host = json_decode(json_encode($package, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $merge = (new PackageJsonMerger)->merge($host, $ours, (bool) $this->option('upgrade-deps'));

        foreach ($merge->added as $add) {
            $package->{$add['section']} ??= new stdClass;
            $package->{$add['section']}->{$add['name']} = $add['version'];
        }

        foreach ($merge->raised as $raise) {
            $package->{$raise['section']}->{$raise['name']} = $raise['to'];
            $this->components->twoColumnDetail($raise['name'], "{$raise['from']} → {$raise['to']}（--upgrade-deps）");
        }

        foreach ($merge->unverified as $item) {
            $this->components->twoColumnDetail($item['name'], "專案指定 {$item['host']}，無法自動比對，請確認相容於 {$item['needs']}");
        }

        $package->scripts ??= new stdClass;

        foreach (self::SCRIPTS as $name => $command) {
            $package->scripts->{$name} ??= $command;
        }

        $json = PackageJsonFile::format($package, $original);

        if ($json !== $original) {
            $files->put($path, $json);
        }

        return $merge;
    }

    /**
     * Prints conflicts as a table. Without --upgrade-deps they fail the install (after everything else
     * was set up), so the developer fixes package.json before running pnpm install.
     */
    private function reportDependencyConflicts(MergeResult $merge): bool
    {
        if ($merge->conflicts === []) {
            return true;
        }

        $upgrade = (bool) $this->option('upgrade-deps');

        $this->message($upgrade ? 'warn' : 'error', 'package.json 裡有 '.count($merge->conflicts).' 個套件的版本和 Brume 不相容，沒有自動修改：');
        $this->table(
            ['套件', '專案目前', 'Brume 需要', '怎麼處理'],
            array_map(fn (array $conflict) => [
                $conflict['name'],
                $conflict['host'],
                $conflict['needs'],
                $conflict['newer']
                    ? 'Brume 還沒支援這個版本：改用相容的版本，或等 Brume 更新'
                    : '把版本改成 '.$this->suggestedSpec($conflict['host'], $conflict['needs']).'，或加 --upgrade-deps 重跑',
            ], $merge->conflicts),
        );

        foreach ($this->conflictReasons($merge) as $name => $reason) {
            $this->line("  {$name}：{$reason}");
        }

        if ($upgrade) {
            return true;
        }

        $this->newLine();
        $this->line('  其他檔案都已設定好；修正 package.json 後再執行 pnpm install。');
        $this->line('  也可以加 --upgrade-deps 重跑，讓 brume:install 把太舊的版本升上來。');
        $this->newLine();

        return false;
    }

    /**
     * Why an older version cannot be kept, from "brume": {"conflictReasons": {...}} in
     * frontend/package.json (next to the versions they explain).
     *
     * @return array<string, string>
     */
    private function conflictReasons(MergeResult $merge): array
    {
        $ours = json_decode((string) file_get_contents(self::$frontendPackagePath), true);
        $reasons = is_array($ours['brume']['conflictReasons'] ?? null) ? $ours['brume']['conflictReasons'] : [];

        $found = [];

        foreach ($merge->conflicts as $conflict) {
            if (! $conflict['newer'] && is_string($reason = $reasons[$conflict['name']] ?? null)) {
                $found[$conflict['name']] = $reason;
            }
        }

        return $found;
    }

    /** The project's own operator with Brume's version, e.g. ^18.3.1 → ^19.3.1. */
    private function suggestedSpec(string $host, string $needs): string
    {
        return (PackageJsonMerger::parse($host)['operator'] ?? '').$needs;
    }

    /** @param  list<string>  $lines */
    private function appendMissingLines(Filesystem $files, string $path, array $lines): void
    {
        if (! $files->exists($path)) {
            return;
        }

        $original = $files->exists($path) ? $files->get($path) : '';
        $existing = array_map('trim', preg_split('/\R/', $original));
        $contents = $original;

        foreach ($lines as $line) {
            if (! in_array($line, $existing, true)) {
                $contents = $this->appendLine($contents, $line);
            }
        }

        if ($contents !== $original) {
            $files->put($path, $contents);
        }
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

        if (! $this->input->isInteractive() && ($this->option('username') === null || $this->option('password') === null)) {
            $this->components->twoColumnDetail('第一個管理員', '沒有建立');
            $this->message('warn', '請加上 --username 與 --password，或之後執行 php artisan brume:user 建立管理員。');

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
        $this->line('  1. pnpm install（或 npm install）');
        $this->line('  2. pnpm brume:dev 啟動開發伺服器；部署前執行 pnpm brume:build');
        $this->newLine();
    }
}
