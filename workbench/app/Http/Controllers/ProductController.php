<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Resource\Resource;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\ProductCategory;
use Workbench\App\Support\ProductPurchaseLimits;

/** 商品: ERP-locked fields, preorder rules, purchase limits and bundle components with public DSL only. */
class ProductController extends Resource
{
    protected string $model = CatalogProduct::class;

    protected string $name = '商品';

    /** The made-up storefront stylesheet (workbench/public/storefront) the editors render with. */
    private const STOREFRONT_CSS = '/storefront/content.css';

    protected function grid(Grid $grid): void
    {
        $grid->title('商品')->description('商品資料、上下架與限購設定。ERP 同步的商品，基本資料唯讀。')
            ->quickSearch('identifier', 'name')
            ->quickSearchPlaceholder('搜尋貨號或商品名稱')
            ->exportable('products');

        $grid->column('identifier', '貨號')->primary()->hideable(false);
        $grid->column('name', '商品名稱');
        $grid->column('is_published', '上架')->switch();
        $grid->column('price', '價格（NT$）')->numeric()->display(fn ($value) => number_format((float) $value));
        $grid->column('stock', '庫存')->numeric();
        $grid->column('is_bundle', '組合商品')->label([0 => '單品', 1 => '組合'], 'accent');
        $grid->column('barcode', '條碼')->hidden();
        $grid->column('updated_at', '更新時間')->hidden()
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));

        $filters = $grid->filters();
        $filters->equal('is_published', '上架狀態')->select([1 => '已上架', 0 => '未上架']);
        $filters->equal('category_id', '分類')->select(ProductCategory::query()->orderBy('id')->pluck('name', 'id')->all());
        $filters->equal('is_bundle', '商品類型')->select([0 => '單品', 1 => '組合商品']);

        $grid->batchActions(
            Action::make('publish', '上架')
                ->confirm('將 {count} 件商品上架？')
                ->handle(fn (Collection $products) => $this->publish($products, true)),
            Action::make('unpublish', '下架')
                ->confirm('將 {count} 件商品下架？')
                ->handle(fn (Collection $products) => $this->publish($products, false)),
        );
    }

    protected function form(Form $form): void
    {
        $form->layout('stacked');
        $erpLocked = fn (?CatalogProduct $product) => $product?->erp_updated_at !== null;
        $isBundle = Conditions::eq(Conditions::root('is_bundle'), true);
        $isPreorder = Conditions::eq(Conditions::root('is_preorder'), true);

        $form->section('基本資料', function (Form $form) use ($erpLocked) {
            $form->text('identifier', '貨號')->required()->maxLength(50)->unique()->readOnly($erpLocked);
            $form->text('name', '商品名稱')->required()->maxLength(120)->readOnly($erpLocked);
            $form->currency('price', '價格（NT$）')->decimals(0)->min(0)->required()->default(0)->readOnly($erpLocked);
            $form->number('stock', '庫存')->min(0)->required()->default(0)->readOnly($erpLocked);
            $form->text('barcode', '條碼')->maxLength(50);
            $form->switch('is_published', '上架')->default(false);
            $form->color('label_color', '標籤顏色')->swatches(['#e53935', '#fb8c00', '#fdd835', '#43a047', '#00897b', '#1e88e5', '#8e24aa', '#546e7a']);
            $form->display('erp_updated_at', 'ERP 同步時間')->value(fn (CatalogProduct $product) => $product->erp_updated_at?->format('Y-m-d H:i'));
            // Written by the ERP sync only: a read-only editor shows the stored HTML (sanitised).
            $form->editor('erp_note', 'ERP 備註')->readOnly()->withoutImages()->contentCss(self::STOREFRONT_CSS)
                ->help('由 ERP 同步，後台唯讀。');
        });
        $form->section('商品介紹', function (Form $form) {
            $form->html('<p>撰寫商品說明前，請先參考<a href="/admin/product-categories">商品分類</a>的命名規則；圖片與標題請維持與其他商品一致。</p>');
            $form->editor('description', '商品說明')->height(520)->contentCss(self::STOREFRONT_CSS)->maxSize(2048)
                ->help('貼上或拖放的圖片會立即上傳（JPEG、PNG、GIF、WebP，2 MB 以內）。');
            // A JSON repeater whose answers are editors: required, and without image uploads.
            $form->repeater('faqs', '常見問題', function (Form $faq) {
                $faq->text('question', '問題')->required()->maxLength(120);
                $faq->editor('answer', '回答')->required()->withoutImages()->height(280)->contentCss(self::STOREFRONT_CSS);
            })->max(6);
        });
        $form->section('圖片', function (Form $form) {
            $form->image('cover', '封面圖');
            $form->multipleImage('gallery', '相簿')->sortable()->max(8)->extra(fn (Form $extra) => $extra->url('link', '連結'));
        });
        $form->section('預購', function (Form $form) use ($isPreorder) {
            $form->switch('is_preorder', '預購商品')->default(false);
            $form->datetime('preorder_ships_at', '預計出貨時間')->required()->showWhen($isPreorder);
            $form->text('preorder_note', '預購說明')->required()->maxLength(255)->showWhen($isPreorder);
        });
        $form->section('銷售與限購', function (Form $form) {
            $form->select('category_id', '分類')
                ->remote(OptionSource::make(fn () => ProductCategory::query()->treeOrder())->label('name')->depth('tree_depth'));
            $form->number('per_order_limit', '每單限購')->min(1)->default(null);
            $form->number('total_limit', '總限購')->min(0)->default(null)
                ->help('留空表示不限量；不可小於已售出數量。')
                ->saveUsing(fn (CatalogProduct $product, $value) => ProductPurchaseLimits::apply($product, $value === null || $value === '' ? null : (int) $value));
            $form->display('remaining_limit', '剩餘可售');
            $form->display('sold_count', '已售出');
        });
        $form->section('組合內容', function (Form $form) use ($isBundle) {
            $form->switch('is_bundle', '組合商品')->default(false);
            $source = OptionSource::make(fn (OptionContext $context) => CatalogProduct::query()
                ->when($context->record, fn ($query, CatalogProduct $root) => $query->whereKeyNot($root->getKey())))
                ->label('name')->search(['name', 'identifier'])->columns(['identifier' => '貨號']);
            $form->relationPicker('components', '組合內容', $source)->relation('components')->orderColumn('position')->showWhen($isBundle)
                ->extra(fn (Form $extra) => $extra->number('quantity', '數量')->min(1)->required());
        });
        $form->deleting(fn (CatalogProduct $product) => $this->deletionBlocker($product));
    }

    /** A product still used by a bundle or handed out as a live gift stays; its references would dangle. */
    private function deletionBlocker(CatalogProduct $product): ?Feedback
    {
        if (($bundle = $product->bundles()->orderBy('catalog_products.id')->first()) !== null) {
            return Feedback::toast('error', "「{$product->name}」是組合商品「{$bundle->name}」的內容，請先從組合中移除再刪除。");
        }
        if (($gift = $product->liveGifts()->with('action.condition.promotion')->orderBy('id')->first()) !== null) {
            return Feedback::toast('error', "「{$product->name}」是滿額贈「{$gift->action->condition->promotion->name}」的贈品，請先移除贈品再刪除。");
        }

        return null;
    }

    /** @param  Collection<int, CatalogProduct>  $products */
    private function publish(Collection $products, bool $published): Feedback
    {
        $products->each(fn (CatalogProduct $product) => $product->forceFill(['is_published' => $published])->save());

        return Feedback::toast('success', $products->count().' 件商品已'.($published ? '上架' : '下架'))->refresh();
    }
}
