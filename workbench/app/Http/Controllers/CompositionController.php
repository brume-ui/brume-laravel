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
        $grid->title('關聯組合')->description('資料庫示範：開啟任一組合，在各門檻切換商品、優惠券或點數，儲存後重新開啟確認。優惠券與點數僅保存設定，不發券、不入帳。');
        $grid->column('title', '名稱')->primary();
        $grid->column('address', '地址')
            ->component('TaiwanAddress')->editable(Editor::component('TaiwanAddress', rules: ['zipcode' => ['required', 'regex:/^[0-9]{3}$/D'], 'address' => ['required', 'string']])
            ->attributes(['zipcode' => 'zipcode', 'county' => 'county', 'district' => 'district', 'address' => 'address']));
        $grid->filters()->equal('owner', '單選商品', 'owner_id')->selectTable(self::source());
        $grid->filters()->in('owners', '多選商品', 'owner_id')->multipleSelectTable(self::source());
    }

    protected function form(Form $form): void
    {
        $form->description('資料庫示範：各門檻可獨立選擇商品、優惠券或點數。切換前的草稿保留到儲存；儲存只更新目前分支，重新開啟後顯示完整既存值。可排序兩列再儲存，核對資料仍屬於原門檻。優惠券與點數僅保存設定，沒有發放、兌換或點數入帳。');
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
                $threshold->radio('gift_type', '贈品類型')->options(['product' => '商品', 'coupon' => '優惠券', 'points' => '點數'])->default('product')->required()->help('新增門檻請先明確點選類型；商品須選至少一件且數量為正整數，優惠券須填代碼、金額與到期時間，點數須為正整數。');
                $threshold->text('coupon_code', '優惠券代碼')->required()->maxLength(255)->showWhen(Conditions::eq(Conditions::row('gift_type'), 'coupon'));
                $threshold->currency('coupon_amount', '優惠券金額')->decimals(2)->required()->pattern('^[0-9]+\\.[0-9]{2}$', '金額須為小數兩位的精確字串。')->rules(['gt:0', 'max:9999999999.99'])->showWhen(Conditions::eq(Conditions::row('gift_type'), 'coupon'));
                $threshold->datetime('coupon_expires_at', '優惠券到期時間')->required()->showWhen(Conditions::eq(Conditions::row('gift_type'), 'coupon'));
                $threshold->number('points', '贈送點數')->min(1)->max(2147483647)->step(1)->required()->showWhen(Conditions::eq(Conditions::row('gift_type'), 'points'));
                $threshold->textarea('points_note', '點數備註')->showWhen(Conditions::eq(Conditions::row('gift_type'), 'points'));
                $threshold->relationPicker('gifts', '贈品', self::source())->relation('gifts')->associateBy('catalog_id')->orderColumn('position')->restoreRemoved()->required()
                    ->showWhen(Conditions::eq(Conditions::row('gift_type'), 'product'))
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
