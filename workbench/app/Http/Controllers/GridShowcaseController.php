<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Content;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\ShowcaseItem;

/**
 * Grid 示範: every display, every inline editor and every filter kind on one list.
 */
class GridShowcaseController
{
    public function __invoke(): Grid
    {
        // owner.name reads a relation on every row: eager load it.
        $grid = Grid::make(ShowcaseItem::query()->with('owner'))
            ->title('Grid 示範')
            ->description('每一種顯示方式、行內編輯器和篩選條件各用一次。')
            ->defaultSort('id', 'desc')
            ->quickSearch('name', 'sku')
            ->quickSearchPlaceholder('搜尋品名或貨號')
            ->editUrl(fn (ShowcaseItem $item) => '#edit-'.$item->getKey());

        $this->columns($grid);
        $this->filters($grid);

        return $grid;
    }

    private function columns(Grid $grid): void
    {
        $colors = array_map(fn (array $color) => $color[0], ShowcaseItem::COLORS);

        // Text, editors and their header filters.
        $grid->column('name', '品名')->primary()->hideable(false)->sortable();
        $grid->column('sku', '貨號')->copyable()->filter('startWith');
        $grid->column('slogan', '標語')->editable(Editor::text(20), ['required', 'string']);
        $grid->column('note', '備註')->editable(Editor::textarea(), ['nullable', 'string', 'max:200'])->filter('input');
        $grid->column('description', '說明')->limit(12)->filter('like');
        $grid->column('category', '分類')->label(ShowcaseItem::CATEGORIES, 'accent')->filter('equal', ShowcaseItem::CATEGORIES);
        $grid->column('status', '狀態')->component('OrderStatus')->filter('checkbox', ShowcaseItem::STATUSES);
        $grid->column('warehouse', '倉庫')->display(fn (string $value) => ShowcaseItem::WAREHOUSES[$value] ?? $value)
            ->editable(Editor::radio(ShowcaseItem::WAREHOUSES))->filter('in', ShowcaseItem::WAREHOUSES);
        $grid->column('price', '售價（NT$）')->numeric()->sortable()->editable(Editor::number(0, 99999, 10), ['required', 'integer'])
            ->filter('between');
        $grid->column('stock', '庫存')->numeric()->badge('info')->filter('gt');
        $grid->column('progress', '上架進度')->progressBar()->filter('ngt');
        $grid->column('rating', '評分')->numeric()->editable(Editor::select(ShowcaseItem::RATINGS))->filter('lt');
        $grid->column('views', '瀏覽數')->numeric()->sortable()->filter('nlt');
        $grid->column('channels', '通路')->editable(Editor::checkbox(ShowcaseItem::CHANNELS));
        $grid->column('is_active', '上架')->switch();
        $grid->column('flags', '標記')->switchGroup(['is_featured' => '精選', 'is_new' => '新品']);

        // Displays.
        $grid->column('tags', '標籤')
            ->display(fn (string $value) => array_values(array_filter(explode(',', $value))))
            ->label(ShowcaseItem::TAGS);
        $grid->column('meta.color', '顏色')->label($colors);
        $grid->column('images', '圖片')->image(32, 32);
        $grid->column('attachments', '附件')->downloadable();
        $grid->column('qrcode', 'QR Code')->display(fn ($value, ShowcaseItem $item) => 'https://brume.test/items/'.$item->sku)->qrcode();
        $grid->column('website', '商品頁')
            ->display(fn ($value, ShowcaseItem $item) => 'brume.test/'.$item->sku)
            ->link(fn (ShowcaseItem $item) => 'https://brume.test/items/'.$item->sku, external: true);
        $grid->column('detail', '明細')->display(fn () => '查看')->button(fn (ShowcaseItem $item) => '#detail-'.$item->getKey());
        $grid->column('variants', '尺寸庫存')->table(['size' => '尺寸', 'stock' => '庫存']);
        $grid->column('specs', '規格')->display(fn () => '規格')->expand(fn (ShowcaseItem $item) => Content::table(
            ['label' => '項目', 'value' => '內容'],
            [
                ['label' => '分類', 'value' => ShowcaseItem::CATEGORIES[$item->category]],
                ['label' => '倉庫', 'value' => ShowcaseItem::WAREHOUSES[$item->warehouse]],
                ['label' => '顏色', 'value' => ShowcaseItem::COLORS[$item->meta['color']][0]],
            ],
        ), '展開規格');
        $grid->column('story', '介紹')->display(fn () => '介紹')
            ->modal(fn (ShowcaseItem $item) => Content::text($item->description), '商品介紹', '查看介紹');
        $grid->column('owner.name', '負責人');
        $grid->column('released_at', '上市日')->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d'));
    }

    private function filters(Grid $grid): void
    {
        $filters = $grid->filters()->columns(2);

        $filters->findInSet('tags', '標籤')->select(ShowcaseItem::TAGS);
        $filters->in('channels', '通路')->json()->checkbox(ShowcaseItem::CHANNELS);
        $filters->equal('color', '顏色', 'meta->color')->select(array_map(fn (array $color) => $color[0], ShowcaseItem::COLORS));
        $filters->group('stock_rule', '庫存條件', ['equal', 'gt', 'lt'], 'stock')->number();

        $filters->newline();
        $filters->equal('owner', '負責人', 'owner_id')->remoteSelect(User::class, 'name');
        $filters->whereBetween('released', '上市日', function (Builder $query, ?string $start, ?string $end) {
            $query->when($start, fn (Builder $q) => $q->whereDate('released_at', '>=', $start))
                ->when($end, fn (Builder $q) => $q->whereDate('released_at', '<=', $end));
        })->date();

        // No input anywhere: for links such as ?filter[owner_id]=2, shown as a chip.
        $filters->hidden('owner_id');
    }
}
