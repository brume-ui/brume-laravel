<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Resource\Resource;
use Workbench\App\Models\HomeBanner;

/** 首頁橫幅: a paged list put in order by dragging, in the 調整順序 dialog or across pages. */
class HomeBannerController extends Resource
{
    protected string $model = HomeBanner::class;

    protected string $name = '首頁橫幅';

    protected function grid(Grid $grid): void
    {
        $grid->title('首頁橫幅')->description('拖曳調整順序；沒有排序值的橫幅排在最後，第一次儲存時一起編號。')
            ->quickSearch('title')
            ->orderable('sort')
            ->perPage(20);

        $grid->column('title', '標題')->primary();
        $grid->column('is_active', '狀態')->label([1 => ['上架中', 'success'], 0 => ['已下架', 'neutral']]);
        $grid->column('link', '連結');

        $grid->filters()->equal('is_active', '上架狀態')->select([1 => '上架中', 0 => '已下架']);
    }

    protected function form(Form $form): void
    {
        $form->text('title', '標題')->required()->maxLength(120);
        $form->url('link', '連結')->maxLength(255);
        $form->switch('is_active', '上架')->default(false);
    }
}
