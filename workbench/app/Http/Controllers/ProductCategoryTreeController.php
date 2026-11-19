<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Grid;
use Workbench\App\Models\ProductCategory;

/** 分類排序: the same categories as 商品分類, put in order and moved between parents by dragging. */
class ProductCategoryTreeController extends ProductCategoryController
{
    protected string $name = '分類排序';

    protected function grid(Grid $grid): void
    {
        $grid->title('分類排序')->description('拖曳調整分類的順序與層級（最多三層）。');

        $grid->column('name', '分類名稱')->primary();
        $grid->column('products_count', '商品數')->numeric();

        $grid->treeView('parent_id', 'position', maxDepth: 3);

        // The save writes position / parent_id without model events, so the stored paths that the
        // 商品分類 list orders by (and takes its indent from) are refreshed here, descendants included.
        $grid->orderSaved(function (array $changedIds): void {
            ProductCategory::query()->whereKey($changedIds)->orderBy('id')->get()
                ->each(fn (ProductCategory $category) => $category->refreshTreePath());
        });
    }
}
