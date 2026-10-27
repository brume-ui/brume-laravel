<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Grid;
use Carbon\CarbonInterface;
use Workbench\App\Models\Order;

class OrderController
{
    public function __invoke(): Grid
    {
        // Newest first. The phase-1 Grid has no search or column sorting yet; the query sets the order.
        $grid = Grid::make(Order::query()->latest()->latest('id'))
            ->title('訂單')
            ->description('最近半年的訂單，最新的在最上面。');

        $grid->column('number', '訂單編號');
        $grid->column('customer', '客戶');
        $grid->column('amount', '金額（NT$）')
            ->numeric()
            ->display(fn (int $value) => number_format($value));
        // The raw status code stays in the row; the OrderStatus component turns it into a label.
        $grid->column('status', '狀態')->component('OrderStatus');
        $grid->column('created_at', '建立時間')
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));

        return $grid->perPage(15);
    }
}
