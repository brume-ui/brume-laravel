<?php

namespace Workbench\App\Http\Controllers;

use Brume\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Workbench\App\Models\Order;

/**
 * Workbench home (config brume.home): order figures as text cards and the registered demo-chart
 * component (workbench/resources/js/brume/components/DemoChart.tsx) with monthly revenue.
 */
class DashboardController
{
    private const MONTHS = 6;

    public function __invoke(): Dashboard
    {
        // Report up to the newest order rather than today, so the seeded demo never goes stale.
        $latest = CarbonImmutable::parse(Order::query()->max('created_at') ?? 'now');
        $revenue = $this->monthlyRevenue($latest);
        $open = Order::query()->whereIn('status', ['pending', 'paid'])->count();

        return Dashboard::make('儀表板')
            ->card('訂單總數', sprintf('%s 筆，其中 %d 筆已取消。', number_format(Order::query()->count()), Order::query()->where('status', 'cancelled')->count()))
            ->card('待處理訂單', sprintf('%d 筆待付款或待出貨。', $open))
            ->card($latest->month.' 月營收', sprintf('NT$ %s（不含已取消的訂單）。', number_format(end($revenue)['value'] ?? 0)))
            ->card('歡迎使用 Brume', '這是 workbench 的示範後台。左側選單有「訂單」列表、「元件展示」和「系統 → 管理員」。')
            ->component('demo-chart', [
                'title' => '近 '.self::MONTHS.' 個月營收',
                'unit' => 'NT$ ',
                'points' => $revenue,
            ]);
    }

    /**
     * Revenue of the MONTHS months ending with $latest's month, cancelled orders excluded.
     *
     * @return list<array{label: string, value: int}>
     */
    private function monthlyRevenue(CarbonImmutable $latest): array
    {
        $first = $latest->startOfMonth()->subMonths(self::MONTHS - 1);
        $orders = Order::query()
            ->where('created_at', '>=', $first)
            ->where('status', '!=', 'cancelled')
            ->get(['amount', 'created_at']);

        return array_map(function (int $i) use ($first, $orders) {
            $month = $first->addMonths($i);

            return [
                'label' => $month->month.' 月',
                'value' => (int) $orders
                    ->filter(fn (Order $order) => $order->created_at->isSameMonth($month))
                    ->sum('amount'),
            ];
        }, range(0, self::MONTHS - 1));
    }
}
