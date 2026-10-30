<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Form;
use Brume\Grid\Content;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Models\User;
use Brume\Resource\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\ShowcaseItem;

/**
 * Grid 示範: every display, every inline editor and every filter kind on one list.
 */
class GridShowcaseController extends Resource
{
    protected string $model = ShowcaseItem::class;

    protected string $name = '商品';

    protected function query(): Builder
    {
        return parent::query()->with('owner');
    }

    protected function grid(Grid $grid): void
    {
        // owner.name reads a relation on every row: eager load it.
        $grid
            ->title('Grid 示範')
            ->description('每一種顯示方式、行內編輯器和篩選條件各用一次。')
            ->defaultSort('id', 'desc')
            ->quickSearch('name', 'sku')
            ->quickSearchPlaceholder('搜尋品名或貨號');

        $this->columns($grid);
        $this->filters($grid);

    }

    protected function form(Form $form): void
    {
        $form->tabs()->viewUrl(fn (ShowcaseItem $item) => route('brume.grid-showcase.index', ['q' => $item->sku], false))
            ->after(['list', 'edit', 'create', 'view']);
        $form->section('基本資料', function (Form $form) {
            $locked = fn (?ShowcaseItem $item) => $item?->is_featured ?? false;
            $form->text('name', '品名')->required()->maxLength(100)->readOnly($locked);
            $form->text('sku', '貨號')->required()->maxLength(50)->unique()->readOnly($locked);
            $form->text('slogan', '標語')->required()->maxLength(20);
            $form->select('category', '分類')->options(['stationery' => ['文具', 0], 'kitchen' => ['廚房', 1], 'textile' => ['布品', 1], 'lighting' => ['燈具', 0]])->required()->default('stationery');
            $form->radio('status', '狀態')->options(ShowcaseItem::STATUSES)->required()->default('pending');
        });
        $form->section('銷售', function (Form $form) {
            $form->currency('price', '售價（NT$）')->decimals(2)->min(0)->required()->default('0.00');
            $form->number('stock', '庫存')->min(0)->required()->default(0);
            $form->decimal('rating', '評分')->decimals(1)->min(0)->max(5)->default('0.0');
            $form->switch('is_active', '上架')->default(true);
            $form->tags('tags', '標籤')->options(ShowcaseItem::TAGS)->default([]);
            $form->date('released_at', '上市日')->required()->default('2026-10-31');
            $form->dateRange('period', '期間', 'starts_at', 'ends_at');
        });
        $form->section('媒體', function (Form $form) {
            $form->image('cover', '封面');
            $form->multipleImage('gallery', '相簿')->sortable()->max(6)->extra(fn (Form $form) => $form->url('link', '連結'));
            $form->multipleFile('documents', '文件')->accept(['application/pdf', 'text/plain'])->max(5);
        });
        $form->section('其他', function (Form $form) {
            $form->email('contact_email', '聯絡信箱');
            $form->mobile('contact_mobile', '聯絡手機');
            $form->url('website', '網站');
            $form->embeds('meta', '附加資訊', function (Form $form) {
                $form->select('color', '顏色')->options(array_map(fn (array $color) => $color[0], ShowcaseItem::COLORS))->default('teal');
                $form->textarea('note', '附註')->maxLength(500);
            });
            $form->password('secret_note', '秘密備註')->maxLength(255);
            $form->divider();
            $form->display('created_at', '建立時間')->value(fn (ShowcaseItem $item) => $item->created_at?->format('Y-m-d H:i'));
        });
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
        $grid->column('price', '售價（NT$）')->numeric()->sortable()->display(fn ($value) => number_format((float) $value, 2))->editable(Editor::currency(decimals: 2), ['required', 'min:0', 'max:99999'])
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
            ->editable(Editor::tags(ShowcaseItem::TAGS))
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
        $grid->column('released_at', '上市日')->editable(Editor::date());
    }

    private function filters(Grid $grid): void
    {
        $filters = $grid->filters()->columns(2);

        $filters->in('tags', '標籤')->json()->select(ShowcaseItem::TAGS);
        $filters->in('channels', '通路')->json()->checkbox(ShowcaseItem::CHANNELS);
        $filters->equal('color', '顏色', 'meta->color')->select(array_map(fn (array $color) => $color[0], ShowcaseItem::COLORS));

        // Three filters fill one and a half rows of two: newline() starts 庫存條件 on a row of its own.
        $filters->newline();
        $filters->group('stock_rule', '庫存條件', ['equal', 'gt', 'lt'], 'stock')->number();
        $filters->equal('owner', '負責人', 'owner_id')->remoteSelect(User::class, 'name');
        $filters->whereBetween('released', '上市日', function (Builder $query, ?string $start, ?string $end) {
            $query->when($start, fn (Builder $q) => $q->whereDate('released_at', '>=', $start))
                ->when($end, fn (Builder $q) => $q->whereDate('released_at', '<=', $end));
        })->date();

        // No input anywhere: for links such as ?filter[owner_id]=2, shown as a chip.
        $filters->hidden('owner_id');
    }
}
