<?php

namespace Brume\Tests\Support\Controllers;

use Brume\Grid\Grid;
use Workbench\App\Models\Order;

/**
 * A plain grid over the workbench orders, for the Route::brumeGrid tests.
 */
class OrderGridController
{
    public function __invoke(): Grid
    {
        $grid = Grid::make(Order::class)->title('Orders');
        $grid->column('number')->sortable();
        $grid->column('customer');

        return $grid;
    }

    public function index(): Grid
    {
        return $this->__invoke()->title('Orders (index)');
    }
}
