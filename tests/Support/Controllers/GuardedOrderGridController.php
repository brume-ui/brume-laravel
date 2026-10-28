<?php

namespace Brume\Tests\Support\Controllers;

use Brume\Grid\Grid;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Workbench\App\Models\Order;

/**
 * Guards itself with controller middleware (HasMiddleware), as a project controller might.
 */
class GuardedOrderGridController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:manage-orders')];
    }

    public function __invoke(): Grid
    {
        return Grid::make(Order::class)->title('Guarded orders');
    }
}
