<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Grid;
use Brume\Models\User;
use Carbon\CarbonInterface;

class AdminUserController
{
    public function __invoke(): Grid
    {
        // `WORKBENCH_EMPTY_LIST=1 composer serve` filters every row out, to look at the list's empty
        // state (the admin you sign in as always exists, so seeding fewer admins can't empty it).
        // getenv(): the dev server receives it as a process variable, which env() does not read here.
        $empty = (bool) getenv('WORKBENCH_EMPTY_LIST');
        $query = User::query()->when($empty, fn ($q) => $q->whereRaw('1 = 0'));

        $grid = Grid::make($query)->title('管理員');

        $grid->column('username', '帳號');
        $grid->column('name', '名稱');
        $grid->column('created_at', '建立時間')
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));

        return $grid->perPage(10);
    }
}
