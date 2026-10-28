<?php

namespace Brume\Tests\Support\Controllers;

use Brume\Grid\Grid;
use Illuminate\Routing\Controller;
use Workbench\App\Models\Order;

/**
 * Guards itself the older way: $this->middleware() in the constructor.
 */
class LegacyGuardedOrderGridController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:manage-orders');
    }

    public function __invoke(): Grid
    {
        return Grid::make(Order::class)->title('Guarded orders');
    }
}
