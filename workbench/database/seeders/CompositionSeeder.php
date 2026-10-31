<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Composition;
use Workbench\App\Models\CompositionCatalog;

/** Independent fixtures: never changes existing Order/GridShowcase seeded assertions. */
class CompositionSeeder extends Seeder
{
    public function run(): void
    {
        for ($id = 1; $id <= 24; $id++) {
            CompositionCatalog::create(['id' => $id, 'name' => sprintf('商品 %02d', $id), 'sku' => sprintf('CP-%02d', $id), 'category' => $id <= 22 ? 'A' : 'B']);
        }
        for ($id = 1; $id <= 8; $id++) {
            $root = Composition::create(['id' => $id, 'title' => '關聯組合 '.$id, 'category' => 'A', 'zipcode' => '100', 'county' => '臺北市', 'district' => '中正區', 'address' => '中山南路1號', 'notes' => [['_brume' => ['key' => 'note-'.$id], 'message' => '原始備註', 'labels' => [['_brume' => ['key' => 'label-'.$id], 'text' => '保留']]]]]);
            $tier = $root->tiers()->create(['name' => '第一級', 'position' => 0]);
            $threshold = $tier->thresholds()->create(['composition_id' => $root->id, 'name' => '門檻一', 'position' => 0, 'amount' => '100.00']);
            $threshold->gifts()->create(['catalog_id' => 1, 'quantity' => 2]);
        }
    }
}
