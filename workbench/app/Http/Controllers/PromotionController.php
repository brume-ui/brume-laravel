<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Options\OptionSource;
use Brume\Resource\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\Promotion;
use Workbench\App\Models\PromotionAction;
use Workbench\App\Models\PromotionFreebie;
use Workbench\App\Support\ChoiceCount;
use Workbench\App\Support\GiftLimit;

/**
 * 滿額贈: thresholds → actions → gifts saved as one relation graph with public DSL only. Gift
 * counters (given_count / already_given_count) are never declared fields, so they are neither
 * shown nor written; they survive reorder, removal and restore of the same gift row.
 */
class PromotionController extends Resource
{
    protected string $model = Promotion::class;

    protected string $name = '滿額贈';

    protected function query(): Builder
    {
        return Promotion::query()->withCount('conditions')->orderBy('id');
    }

    protected function grid(Grid $grid): void
    {
        $grid->title('滿額贈')->description('消費滿指定金額即贈送商品；每個門檻可設定全部送或任選 N 樣。');

        $grid->column('name', '活動名稱')->primary();
        $grid->column('period', '活動期間')
            ->display(fn ($value, Promotion $promotion) => $promotion->starts_at?->format('Y-m-d H:i').' ～ '.$promotion->ends_at?->format('Y-m-d H:i'));
        $grid->column('is_enabled', '啟用')->switch();
        $grid->column('conditions_count', '門檻數')->numeric();
    }

    protected function form(Form $form): void
    {
        $products = OptionSource::make(CatalogProduct::class)->label('name')->search(['identifier', 'name'])
            ->columns(['identifier' => '商品編號', 'price' => '價格']);

        $form->section('活動設定', function (Form $form) {
            $form->text('name', '活動名稱')->required()->maxLength(120);
            $form->datetimeRange('period', '活動期間', 'starts_at', 'ends_at')->required();
            $form->switch('is_enabled', '啟用')->default(true);
        });
        $form->section('門檻與贈品', function (Form $form) use ($products) {
            $form->component('calculator', '即時試算', 'PromotionCalculator')->displayOnly()->span('full');
            $form->repeater('conditions', '消費門檻', function (Form $condition) use ($products) {
                $condition->currency('amount', '消費滿（NT$）')->decimals(2)->min(0.01)->required();
                $condition->repeater('actions', '贈送方式', function (Form $action) use ($products) {
                    $action->radio('strategy', '贈送方式')->options(['all' => '全部送', 'choose' => '任選 N 樣'])->default('all')->required();
                    $action->number('choose_count', '任選數量')->required()
                        ->showWhen(Conditions::eq(Conditions::row('strategy'), 'choose'))
                        ->rules(fn (?PromotionAction $action) => [new ChoiceCount($action)]);
                    $action->relationPicker('freebies', '贈品', $products)->relation('freebies')
                        ->associateBy('product_id')->restoreRemoved()->orderColumn('position')->required()
                        ->extra(function (Form $gift) {
                            $gift->switch('is_limited', '限量')->default(false);
                            $gift->number('given_limit', '限量數')->required()
                                ->showWhen(Conditions::eq(Conditions::row('is_limited'), true))
                                ->rules(fn (?PromotionFreebie $freebie) => [GiftLimit::for($freebie)]);
                        });
                })->relation('actions')->orderColumn('position')->min(1);
            })->relation('conditions')->orderColumn('position')->distinct('amount')->min(1);
        });
    }
}
