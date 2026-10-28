<?php

namespace Workbench\App\Http\Controllers;

use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Workbench\App\Models\Order;

/**
 * 訂單: a list set up the way an everyday admin page would be (Route::brumeGrid in
 * workbench/routes/brume.php).
 */
class OrderController
{
    /** Status label tones; the codes are Order::STATUSES. */
    private const STATUS_TONES = [
        'pending' => 'warning',
        'paid' => 'info',
        'shipped' => 'accent',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];

    public function __invoke(): Grid
    {
        $grid = Grid::make(Order::query())
            ->title('訂單')
            ->description('最近半年的訂單，最新的在最上面。')
            ->defaultSort('created_at', 'desc')
            ->quickSearch('number', 'customer')
            ->quickSearchPlaceholder('搜尋訂單編號或客戶')
            ->perPage(15)
            ->perPageOptions([15, 50, 100])
            // Sub-plan 2 replaces the stub with the edit form.
            ->editUrl(fn (Order $order) => '#edit-'.$order->getKey())
            ->deletable()
            ->exportable();

        $statuses = array_map(
            fn (string $code) => [Order::STATUS_LABELS[$code], self::STATUS_TONES[$code]],
            array_combine(Order::STATUSES, Order::STATUSES),
        );

        $grid->column('number', '訂單編號')->primary()->hideable(false);
        $grid->column('customer', '客戶');
        $grid->column('category', '分類');
        // Narrow widths for the short columns keep the default columns within 1440px with the
        // sidebar expanded (other columns get the fluid 10rem minimum).
        $grid->column('amount', '金額（NT$）')
            ->width('8rem')
            ->numeric()
            ->sortable()
            ->display(fn (int $value) => number_format($value))
            ->exportUsing(fn (int $value) => $value);
        $grid->column('status', '狀態')
            ->width('7rem')
            ->label($statuses)
            ->editable(Editor::select(Order::STATUS_LABELS));
        $grid->column('created_at', '建立時間')
            ->sortable()
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));
        $grid->column('updated_at', '更新時間')
            ->hidden()
            ->display(fn (?CarbonInterface $value) => $value?->format('Y-m-d H:i'));

        $this->filters($grid);

        $ship = fn () => Action::make('ship', '標記出貨')->icon('truck');

        $grid->rowActions(
            $ship()
                ->exposed()
                ->visible(fn (Order $order) => $order->status === 'paid')
                ->handle(fn (Collection $orders) => $this->ship($orders)),
            Action::make('show_number', '顯示訂單編號')
                ->icon('hash')
                ->handle(fn (Collection $orders) => Feedback::toast('info', '訂單編號：'.$orders->first()->number)),
        );
        $grid->batchActions(
            $ship()
                ->confirm('將 {count} 筆訂單標記為已出貨？', '只有已付款的訂單會改成已出貨，其他狀態的訂單不變。')
                ->handle(fn (Collection $orders) => $this->ship($orders)),
        );

        return $grid;
    }

    private function filters(Grid $grid): void
    {
        $filters = $grid->filters();

        $filters->equal('status', '狀態')->select(Order::STATUS_LABELS);
        $filters->in('category', '分類')->multipleSelect(array_combine(Order::CATEGORIES, Order::CATEGORIES));
        // One option per customer name: the oldest order of each customer.
        $filters->equal('customer', '客戶')->remoteSelect(
            Order::query()->whereIn('id', fn ($query) => $query->selectRaw('MIN(id)')->from('orders')->groupBy('customer')),
            'customer',
            'customer',
        );
        $filters->between('amount', '金額（NT$）')->number();
        $filters->between('created_at', '建立時間')->date();

        // 本月 is the month of the newest order, so the seeded demo never goes empty.
        $filters->scope('this_month', '本月', fn (Builder $query) => $query->where(
            'created_at',
            '>=',
            CarbonImmutable::parse(Order::query()->max('created_at') ?? 'now')->startOfMonth(),
        ));

        $filters->selector('stage', '狀態', Order::STATUS_LABELS, multiple: true, column: 'status');
    }

    /**
     * Paid orders become shipped; the others are left as they are.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function ship(Collection $orders): Feedback
    {
        $paid = $orders->where('status', 'paid');
        $paid->each(fn (Order $order) => $order->forceFill(['status' => 'shipped'])->save());

        $message = match (true) {
            $orders->count() === 1 && $paid->isNotEmpty() => "{$paid->first()->number} 已標記出貨",
            default => "{$paid->count()} 筆訂單已標記出貨",
        };

        return Feedback::toast('success', $message)->refresh();
    }
}
