<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('upgrades an isolated pre-gift database with the narrow migration and preserves legacy IDs counters and files', function () {
    $originalConnection = config('database.default');
    $mysql = getenv('BRUME_TEST_DB') === 'mysql';
    $fixture = storage_path('framework/testing/gift-upgrade-'.bin2hex(random_bytes(6)).'.sqlite');
    if (! is_dir(dirname($fixture))) {
        mkdir(dirname($fixture), 0755, true);
    }
    if (! $mysql) {
        touch($fixture);
    }
    // SQLite uses a new physical file; CI MySQL uses only the already isolated test DB,
    // with a separate table prefix. Neither can resolve the workbench trial database.
    config(['database.connections.gift_upgrade' => $mysql
        ? [...config('database.connections.mysql'), 'prefix' => 'gift_upgrade_']
        : ['driver' => 'sqlite', 'database' => $fixture, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $legacy = realpath(__DIR__.'/../../../workbench/database/migrations/2026_10_31_100000_create_composition_tables.php');
    $upgrade = realpath(__DIR__.'/../../../workbench/database/migrations/2026_11_01_000001_add_gift_demo_types_to_composition_nodes.php');
    $options = ['--database' => 'gift_upgrade', '--realpath' => true, '--force' => true];
    Storage::fake('public');
    $attachment = 'brume/legacy/gift.txt';
    Storage::disk('public')->put($attachment, 'legacy gift attachment');
    $hash = hash('sha256', Storage::disk('public')->get($attachment));
    $tables = ['compositions', 'composition_catalog', 'composition_featured', 'composition_nodes', 'composition_gifts'];
    $db = DB::connection('gift_upgrade');
    try {
        $this->artisan('migrate', $options + ['--path' => $legacy])->assertSuccessful();
        expect(Schema::connection('gift_upgrade')->hasColumn('composition_nodes', 'gift_type'))->toBeFalse();
        $db->table('compositions')->insert(['id' => 61, 'title' => '舊資料', 'notes' => json_encode([['message' => '原始備註', 'attachment' => $attachment]])]);
        $db->table('composition_catalog')->insert(['id' => 51, 'name' => '舊商品', 'category' => 'A', 'sku' => 'OLD-51']);
        $db->table('composition_featured')->insert(['composition_id' => 61, 'catalog_id' => 51, 'quantity' => 3, 'position' => 9]);
        $db->table('composition_nodes')->insert([
            ['id' => 71, 'composition_id' => 61, 'parent_id' => null, 'name' => '舊級距', 'amount' => '0.00', 'position' => 4, 'attachment' => $attachment],
            ['id' => 72, 'composition_id' => 61, 'parent_id' => 71, 'name' => '舊門檻', 'amount' => '1234.56', 'position' => 8, 'attachment' => $attachment],
        ]);
        $db->table('composition_gifts')->insert([
            ['id' => 81, 'node_id' => 72, 'catalog_id' => 51, 'quantity' => 4, 'position' => 7, 'counter' => 93, 'attachment' => $attachment, 'deleted_at' => null],
            ['id' => 82, 'node_id' => 72, 'catalog_id' => 52, 'quantity' => 2, 'position' => 9, 'counter' => 47, 'attachment' => $attachment, 'deleted_at' => '2026-10-31 12:00:00'],
        ]);
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $db->table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $this->artisan('migrate', $options + ['--path' => $upgrade])->assertSuccessful();
        foreach ($tables as $table) {
            $after = $db->table($table)->get()->map(fn ($row) => (array) $row)->all();
            foreach ($before[$table] as $index => $row) {
                expect(array_intersect_key($after[$index], $row))->toBe($row);
            }
        }
        expect($db->table('composition_nodes')->pluck('gift_type')->all())->toBe(['product', 'product'])
            ->and($db->table('composition_nodes')->where('id', 72)->value('coupon_amount'))->toBeNull()
            ->and(hash('sha256', Storage::disk('public')->get($attachment)))->toBe($hash);
        $db->table('composition_nodes')->where('id', 72)->update(['gift_type' => 'points', 'points' => 125, 'points_note' => 'rollback loses this setting']);
        // Down is intentionally destructive only to the six new settings. Exercise it solely
        // here, never in the original/live workbench, then prove the narrow upgrade reapplies.
        $this->artisan('migrate:rollback', $options + ['--path' => $upgrade, '--step' => 1])->assertSuccessful();
        expect(Schema::connection('gift_upgrade')->hasColumn('composition_nodes', 'gift_type'))->toBeFalse();
        foreach ($tables as $table) {
            expect($db->table($table)->get()->map(fn ($row) => (array) $row)->all())->toBe($before[$table]);
        }
        expect(hash('sha256', Storage::disk('public')->get($attachment)))->toBe($hash);
        $this->artisan('migrate', $options + ['--path' => $upgrade])->assertSuccessful();
        expect($db->table('composition_nodes')->where('id', 72)->value('gift_type'))->toBe('product')
            ->and($db->table('composition_nodes')->where('id', 72)->value('points'))->toBeNull()
            ->and($db->table('composition_gifts')->where('id', 81)->value('counter'))->toBe(93);
    } finally {
        // Explicit connection for cleanup; no migration refresh, fresh or seed is used.
        foreach (array_reverse([...$tables, 'migrations']) as $table) {
            Schema::connection('gift_upgrade')->dropIfExists($table);
        }
        DB::setDefaultConnection($originalConnection);
        DB::purge('gift_upgrade');
        if (! $mysql && is_file($fixture)) {
            unlink($fixture);
        }
    }
});
