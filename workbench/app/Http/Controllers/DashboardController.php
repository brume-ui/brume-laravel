<?php

namespace Workbench\App\Http\Controllers;

use Brume\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Workbench\App\Models\Order;

/**
 * Workbench home (config brume.home): brume-react's charts fed from the seeded 訂單. Every figure
 * is computed here and passed as props to the registered KpiRow and ChartCard components
 * (workbench/resources/js/brume/components/).
 */
class DashboardController
{
    /** Months in the stat cards' sparklines. */
    private const MONTHS = 6;

    /** Weeks in the revenue line. The seed has one order every three days, too sparse to plot daily. */
    private const WEEKS = 12;

    private const TWD = ['style' => 'currency', 'currency' => 'TWD', 'maximumFractionDigits' => 0];

    public function __invoke(): Dashboard
    {
        $orders = Order::query()->oldest()->get(['customer', 'amount', 'status', 'category', 'created_at']);
        // Report up to the newest order rather than today, so the seeded demo never goes stale.
        $latest = CarbonImmutable::parse($orders->last()?->created_at ?? 'now');
        $paid = $orders->where('status', '!=', 'cancelled');
        $open = $orders->whereIn('status', ['pending', 'paid'])->count();

        return Dashboard::make('儀表板')
            ->component('KpiRow', ['cards' => $this->statCards($orders, $latest)])
            ->component('ChartCard', [
                'chart' => 'line',
                'span' => 'wide',
                'title' => '每週營收',
                'description' => '近 '.self::WEEKS.' 週，不含已取消的訂單',
                'data' => $this->weeklyRevenue($paid, $latest),
                'xKey' => 'week',
                'xType' => 'time',
                'series' => [['key' => 'revenue', 'label' => '營收']],
                'area' => true,
                'valueFormat' => self::TWD,
                // Matches the 訂單狀態 card beside it from xl up.
                'height' => 420,
            ])
            ->component('ChartCard', [
                'chart' => 'donut',
                'title' => '訂單狀態',
                'description' => '全部 '.$orders->count().' 筆訂單',
                'data' => array_map(fn (string $status) => [
                    'label' => Order::STATUS_LABELS[$status],
                    'value' => $orders->where('status', $status)->count(),
                ], Order::STATUSES),
                'centerLabel' => '筆訂單',
            ])
            ->component('ChartCard', [
                'chart' => 'bar',
                'title' => '分類營收',
                'description' => '全部期間，不含已取消的訂單',
                'data' => array_map(fn (string $category) => [
                    'category' => $category,
                    'revenue' => $paid->where('category', $category)->sum('amount'),
                ], Order::CATEGORIES),
                'categoryKey' => 'category',
                // A third of the grid is too narrow to label six vertical bars.
                'orientation' => 'horizontal',
                'series' => [['key' => 'revenue', 'label' => '營收']],
                'valueFormat' => self::TWD,
            ])
            ->card('待處理訂單', sprintf('%d 筆待付款或待出貨。', $open))
            ->card('歡迎使用 Brume', '這是 workbench 的示範後台。左側選單有「訂單」列表、「元件展示」和「系統 → 管理員」。');
    }

    /**
     * 營收, 訂單數, 平均客單價 and 新客戶 for $latest's month, each with the change vs the month
     * before and the last MONTHS months as a sparkline.
     *
     * @param  Collection<int, Order>  $orders  oldest first
     * @return list<array<string, mixed>>
     */
    private function statCards(Collection $orders, CarbonImmutable $latest): array
    {
        $firstOrderAt = $orders->groupBy('customer')->map(fn (Collection $own) => $own->first()->created_at);
        $months = array_map(function (int $back) use ($orders, $latest, $firstOrderAt) {
            $month = $latest->startOfMonth()->subMonths($back);
            $inMonth = $orders->filter(fn (Order $order) => $order->created_at->isSameMonth($month));
            $paid = $inMonth->where('status', '!=', 'cancelled');
            $revenue = (int) $paid->sum('amount');

            return [
                'revenue' => $revenue,
                'orders' => $inMonth->count(),
                'cancelled' => $inMonth->count() - $paid->count(),
                'average' => $paid->isEmpty() ? 0 : (int) round($revenue / $paid->count()),
                'customers' => $firstOrderAt->filter(fn ($at) => $at->isSameMonth($month))->count(),
            ];
        }, range(self::MONTHS - 1, 0));

        $card = function (string $label, string $key, ?string $hint = null, ?array $format = null) use ($months) {
            $current = $months[self::MONTHS - 1][$key];
            $previous = $months[self::MONTHS - 2][$key];

            return array_filter([
                'label' => $label,
                'value' => $current,
                'format' => $format,
                // No change to show when the month before had nothing.
                'delta' => $previous > 0 ? ['value' => $current / $previous - 1, 'period' => '上月'] : null,
                'trend' => array_column($months, $key),
                'hint' => $hint,
            ], fn ($value) => $value !== null);
        };
        $month = $latest->month.' 月';

        return [
            $card('營收', 'revenue', $month.'，統計至 '.$latest->format('n/j'), self::TWD),
            $card('訂單數', 'orders', $month.'，含已取消 '.$months[self::MONTHS - 1]['cancelled'].' 筆'),
            $card('平均客單價', 'average', $month.'，不含已取消的訂單', self::TWD),
            $card('新客戶', 'customers', $month.'第一次下單的客戶'),
        ];
    }

    /**
     * Revenue per week (Monday start) for the WEEKS weeks ending with $latest's week.
     *
     * @param  Collection<int, Order>  $paid
     * @return list<array{week: string, revenue: int}>
     */
    private function weeklyRevenue(Collection $paid, CarbonImmutable $latest): array
    {
        $first = $latest->startOfWeek()->subWeeks(self::WEEKS - 1);

        return array_map(function (int $i) use ($first, $paid) {
            $week = $first->addWeeks($i);

            return [
                'week' => $week->toDateString(),
                'revenue' => (int) $paid
                    ->filter(fn (Order $order) => $order->created_at->between($week, $week->endOfWeek()))
                    ->sum('amount'),
            ];
        }, range(0, self::WEEKS - 1));
    }
}
