import {
  BarChart,
  type BarChartProps,
  Card,
  DonutChart,
  type DonutChartProps,
  LineChart,
  type LineChartProps,
} from '@brume-ui/brume-react';

export type ChartCardProps = (
  | ({ chart: 'line' } & LineChartProps)
  | ({ chart: 'bar' } & BarChartProps)
  | ({ chart: 'donut' } & DonutChartProps)
) & {
  /** `wide` spans two dashboard columns from md up. */
  span?: 'wide';
};

/**
 * Dashboard block: one brume-react chart in a Card. PHP picks the chart and passes its props
 * as-is (DashboardController); the chart's own title heads the card.
 */
export function ChartCard({ span, context: _context, ...props }: ChartCardProps & { context?: unknown }) {
  return (
    <div className={span === 'wide' ? 'grid md:col-span-2' : 'grid'} data-chart-card={props.chart}>
      <Card>
        {props.chart === 'line' ? (
          <LineChart {...props} />
        ) : props.chart === 'bar' ? (
          <BarChart {...props} />
        ) : (
          <DonutChart {...props} />
        )}
      </Card>
    </div>
  );
}
