<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\HomeBanner;
use Workbench\App\Models\NavMenu;

/**
 * Deterministic 網站選單 / 首頁橫幅 demo data for the drag-to-order pages. Every row has a fixed id,
 * so running it again resets the same rows and touches nothing else.
 */
class OrderingSeeder extends Seeder
{
    public const NAV_MENUS = 18;

    public const HOME_BANNERS = 45;

    /** Banners numbered from this one on have no sort value yet (they list last). */
    public const FIRST_UNSORTED_BANNER = 41;

    public function run(): void
    {
        $this->seedNavMenus();
        $this->seedHomeBanners();
    }

    private function seedNavMenus(): void
    {
        $tree = [
            ['關於我們', null, [['品牌故事', '/about/story'], ['團隊介紹', '/about/team'], ['聯絡我們', '/contact']]],
            ['商品專區', '/shop', [['新品上市', '/shop/new'], ['熱銷排行', '/shop/best'], ['限時特價', '/shop/sale']]],
            ['會員服務', null, [['會員登入', '/member/login'], ['訂單查詢', '/member/orders'], ['常見問題', '/help/faq']]],
            ['最新消息', '/news', [['活動公告', '/news/events'], ['媒體報導', '/news/press']]],
            ['門市據點', '/stores', [['台北門市', '/stores/taipei'], ['舊版門市地圖', null]]],
        ];

        // Roots take ids 1-5, their sub-menus follow from 6.
        $childId = count($tree);
        foreach ($tree as $index => [$name, $url, $children]) {
            NavMenu::query()->updateOrCreate(['id' => $index + 1], ['parent_id' => null, 'name' => $name, 'url' => $url, 'enabled' => true, 'order' => $index + 1]);

            foreach ($children as $position => [$childName, $childUrl]) {
                NavMenu::query()->updateOrCreate(['id' => ++$childId], [
                    'parent_id' => $index + 1,
                    'name' => $childName,
                    'url' => $childUrl,
                    // The sub-menu without an address is the one switched off.
                    'enabled' => $childUrl !== null,
                    'order' => $position + 1,
                ]);
            }
        }
    }

    private function seedHomeBanners(): void
    {
        $campaigns = [
            '雙 11 購物節', '週年慶全館 85 折', '新會員首購禮', '秋冬新品上市', '滿額免運專案',
            '會員日雙倍點數', '聖誕禮物提案', '跨年倒數特賣', '春季換季大會', '母親節感恩回饋',
            '夏日清涼特輯', '開學季精選', '黑色星期五', '年終出清', '品牌聯名限定',
        ];
        $slots = ['主視覺', '手機版', '側邊推薦'];

        for ($i = 1; $i <= self::HOME_BANNERS; $i++) {
            HomeBanner::query()->updateOrCreate(['id' => $i], [
                'title' => $campaigns[($i - 1) % count($campaigns)].'｜'.$slots[intdiv($i - 1, count($campaigns))],
                'link' => $i % 6 === 0 ? null : sprintf('https://shop.example.test/campaigns/%03d', $i),
                'is_active' => $i % 4 !== 0,
                'sort' => $i < self::FIRST_UNSORTED_BANNER ? $i : null,
                'created_at' => '2026-10-27 12:00:00',
                'updated_at' => '2026-10-27 12:00:00',
            ]);
        }
    }
}
