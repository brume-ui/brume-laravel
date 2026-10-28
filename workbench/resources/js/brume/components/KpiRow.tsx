import { StatCard, type StatCardProps } from '@brume-ui/brume-react';

/**
 * Dashboard block: the headline StatCards in one row across the full dashboard grid (one per row
 * on phones, two on tablets, four from xl). Values, deltas and trends come from PHP
 * (DashboardController).
 */
export function KpiRow({ cards }: { cards: StatCardProps[] }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:col-span-2 xl:col-span-3 xl:grid-cols-4" data-kpi-row>
      {cards.map((card) => (
        <StatCard key={card.label} {...card} />
      ))}
    </div>
  );
}
