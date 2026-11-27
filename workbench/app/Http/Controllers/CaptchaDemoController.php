<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Resource\Resource;
use Workbench\App\Models\CaptchaDemo;

/**
 * 驗證碼示範: a separate form so the main 商品 form stays submittable without a captcha.
 * The answer is fixed in the workbench (WorkbenchServiceProvider), so the page is demoable and testable.
 */
class CaptchaDemoController extends Resource
{
    protected string $model = CaptchaDemo::class;

    protected string $name = '驗證碼示範';

    protected function grid(Grid $grid): void
    {
        $grid->title('驗證碼示範')->description('送出表單前需要輸入圖片中的文字；列表本身不受驗證碼保護。')
            ->defaultSort('id', 'desc');

        $grid->column('message', '留言')->primary();
        $grid->column('created_at', '建立時間');
    }

    protected function form(Form $form): void
    {
        $form->text('message', '留言')->required()->maxLength(100);
        $form->captcha('驗證碼')->help('示範環境的答案固定為 7777。');
    }
}
