<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Options\OptionSource;
use Brume\Resource\Resource;
use Workbench\App\Models\Composition;
use Workbench\App\Models\CompositionCatalog;

/** Uses public DSL only: no custom option endpoints or relation-saving hooks. */
class CompositionController extends Resource
{
    protected string $model = Composition::class;

    protected string $name = '關聯組合';

    private static function source(): OptionSource
    {
        return OptionSource::make(CompositionCatalog::class)->label('name')->search(['name', 'sku'])
            ->columns(['sku' => '貨號'])->sections(['all' => '全部', 'A' => 'A 類'], fn ($query, $section) => $query->when($section === 'A', fn ($query) => $query->where('category', 'A')));
    }

    protected function grid(Grid $grid): void
    {
        $grid->title('關聯組合')->description('關聯、JSON、條件欄位與巢狀上傳的完整範例。');
        $grid->column('title', '名稱')->primary();
        $grid->column('address', '地址')
            ->component('TaiwanAddress')->editable(Editor::component('TaiwanAddress', rules: ['zipcode' => ['required', 'regex:/^[0-9]{3}$/D'], 'address' => ['required', 'string']])
            ->attributes(['zipcode' => 'zipcode', 'county' => 'county', 'district' => 'district', 'address' => 'address']));
        $grid->filters()->equal('owner', '單選商品', 'owner_id')->selectTable(self::source());
        $grid->filters()->in('owners', '多選商品', 'owner_id')->multipleSelectTable(self::source());
    }

    protected function form(Form $form): void
    {
        $form->text('title', '名稱')->required();
        $form->select('category', '分類')->options(['A' => 'A 類', 'B' => 'B 類'])->default('A');
        $dependent = OptionSource::make(fn ($context) => CompositionCatalog::where('category', $context->dependencies['category'] ?? 'A'))->label('name')->columns(['sku' => '貨號']);
        $form->switch('delivery', '需要配送')->default(false);
        $form->text('delivery_note', '配送說明')->required()->showWhen(Conditions::eq(Conditions::root('delivery'), true));
        $form->component('address', '配送地址', 'TaiwanAddress')->attributes(['zipcode' => 'zipcode', 'county' => 'county', 'district' => 'district', 'address' => 'address'])
            ->childRules(['zipcode' => ['required', 'regex:/^[0-9]{3}$/D'], 'county' => ['required', 'string'], 'district' => ['required', 'string'], 'address' => ['required', 'string']]);
        $form->relationPicker('owner', '負責商品', self::source())->relation('owner')->multiple(false)->extra(fn (Form $row) => $row->text('note', '商品備註'));
        $form->relationPicker('candidates', '直接關聯商品', self::source())->relation('candidates');
        $form->relationPicker('featured', '精選商品', self::source())->relation('featured')->orderColumn('position')->extra(fn (Form $row) => $row->number('quantity', '精選數量')->min(1)->required());
        $this->jsonNotes($form);
        $form->repeater('tiers', '級距', function (Form $tier) use ($dependent) {
            $tier->text('name', '級距名稱')->required();
            $tier->file('attachment', '級距附件')->accept(['text/plain']);
            $this->jsonNotes($tier);
            $tier->repeater('thresholds', '門檻', function (Form $threshold) use ($dependent) {
                $threshold->text('name', '門檻名稱')->required();
                $threshold->currency('amount', '門檻金額')->decimals(2)->required();
                $threshold->relationPicker('gifts', '贈品', self::source())->relation('gifts')->associateBy('catalog_id')->orderColumn('position')->restoreRemoved()
                    ->remote($dependent, ['category' => Conditions::root('category')])
                    ->extra(function (Form $gift) {
                        $gift->number('quantity', '贈品數量')->min(1)->required()->rules([
                            function (string $attribute, mixed $value, \Closure $fail): void {
                                if ($value > 5) {
                                    $fail('贈品數量不可超過 5。');
                                }
                            },
                        ]);
                        $gift->file('attachment', '贈品附件')->accept(['text/plain']);
                    });
            })->relation('thresholds')->orderColumn('position')->min(1)->distinct('amount');
        })->relation('tiers')->orderColumn('position')->min(1);
    }

    private function jsonNotes(Form $form): void
    {
        $form->repeater('notes', 'JSON 備註', function (Form $row) {
            $row->text('message', '備註內容')->required();
            $row->file('attachment', '備註附件')->accept(['text/plain']);
            $row->repeater('labels', 'JSON 標籤', fn (Form $label) => $label->text('text', '標籤文字')->required());
        })->max(5);
    }
}
