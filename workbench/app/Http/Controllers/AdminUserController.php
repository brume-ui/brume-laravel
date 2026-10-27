<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Grid;
use Brume\Models\User;
use Carbon\CarbonInterface;

class AdminUserController
{
    public function __invoke(): Grid
    {
        $grid = Grid::make(User::class)->title('管理員');

        $grid->column('username', '帳號');
        $grid->column('name', '名稱');
        $grid->column('created_at', '建立時間')
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));

        return $grid->perPage(10);
    }
}
