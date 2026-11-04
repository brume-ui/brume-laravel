<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Resource\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\ProductCategory;

/** 商品分類: an indented hierarchy and product reassignment with public DSL only. */
class ProductCategoryController extends Resource
{
    protected string $model = ProductCategory::class;

    protected string $name = '商品分類';

    protected function query(): Builder
    {
        return ProductCategory::query()->withCount('products')->orderBy('position')->orderBy('id');
    }

    protected function grid(Grid $grid): void
    {
        $grid->title('商品分類')->description('分類以縮排顯示層級；在編輯頁指定分類底下的商品。');

        $grid->column('name', '分類名稱')->primary()
            ->display(fn ($value, ProductCategory $category) => str_repeat('　', $category->tree_depth).($category->tree_depth > 0 ? '└ ' : '').$value);
        $grid->column('products_count', '商品數')->numeric();
    }

    protected function form(Form $form): void
    {
        $form->text('name', '分類名稱')->required()->maxLength(80);
        $form->select('parent_id', '上層分類')->remote(
            OptionSource::make(fn (OptionContext $context) => ProductCategory::query()
                ->when($context->record, fn ($query, ProductCategory $category) => $query->whereNotIn('id', [$category->getKey(), ...$category->descendantIds()])))
                ->label('name')->depth('tree_depth'),
        );
        $source = OptionSource::make(CatalogProduct::class)
            ->label('name')->search(['identifier', 'name'])->columns(['identifier' => '商品編號']);
        $form->relationPicker('products', '商品', $source)->relation('products')
            ->help('選入的商品會從原本的分類移過來；取消選取的商品會變成未分類，不會被刪除。');
    }
}
