<?php

namespace Workbench\App\Http\Controllers;

use Brume\Form\Form;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Resource\Resource;
use Workbench\App\Models\NavMenu;

/** 網站選單: a two-level tree, put in order and moved between parents by dragging. */
class NavMenuController extends Resource
{
    protected string $model = NavMenu::class;

    protected string $name = '網站選單';

    protected function grid(Grid $grid): void
    {
        $grid->title('網站選單')->description('拖曳調整順序，或拖到另一個選單底下成為子項（最多兩層）。');

        $grid->column('name', '名稱')->primary();
        $grid->column('url', '網址');
        $grid->column('enabled', '狀態')->display(fn ($value) => $value ? null : '停用')->badge('warning');

        $grid->treeView(maxDepth: 2);
    }

    protected function form(Form $form): void
    {
        $form->text('name', '名稱')->required()->maxLength(80);
        // Only root menus can be a parent (two levels); a menu that has sub-menus stays a root.
        $form->select('parent_id', '上層選單')->remote(
            OptionSource::make(fn (OptionContext $context) => NavMenu::query()->whereNull('parent_id')->orderBy('order')->orderBy('id')
                ->when($context->record, fn ($query, NavMenu $menu) => $menu->children()->exists()
                    ? $query->whereRaw('1 = 0')
                    : $query->whereKeyNot($menu->getKey())))
                ->label('name'),
        )->clearable()->placeholder('無（頂層）');
        $form->text('url', '網址')->maxLength(255);
        $form->switch('enabled', '啟用')->default(true);
        $form->deleting(fn (NavMenu $menu) => $menu->children()->exists()
            ? Feedback::toast('error', "「{$menu->name}」底下還有子項，請先刪除或移走子項。")
            : null);
    }
}
