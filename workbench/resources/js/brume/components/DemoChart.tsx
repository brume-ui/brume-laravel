import { Card } from '@brume-ui/brume-react';

export interface DemoChartPoint {
  label: string;
  value: number;
}

export interface DemoChartProps {
  title: string;
  /** Prefix for the value labels, e.g. "NT$". */
  unit?: string;
  points: DemoChartPoint[];
}

const format = (value: number) => value.toLocaleString('zh-TW');

/**
 * Dashboard block: a dependency-free bar chart in Brume tokens. The data comes from PHP
 * (DashboardController); the last bar is the current period and gets the stronger color.
 * Spans two dashboard columns from md up.
 */
export function DemoChart({ title, unit = '', points }: DemoChartProps) {
  const max = Math.max(1, ...points.map((p) => p.value));
  const summary = points.map((p) => `${p.label} ${unit}${format(p.value)}`).join('、');

  return (
    <div className="grid md:col-span-2" data-demo-chart>
      <Card title={title}>
        <div role="img" aria-label={`${title}：${summary}`} className="flex h-56 items-end gap-3">
          {points.map((point, i) => {
            const current = i === points.length - 1;
            return (
              <div key={point.label} className="flex h-full min-w-0 flex-1 flex-col items-center gap-2">
                <span className="text-xs font-semibold tabular-nums text-text-muted">
                  {unit}
                  {format(point.value)}
                </span>
                <div className="flex w-full flex-1 items-end justify-center">
                  <div
                    className={`w-full max-w-12 rounded-t-input ${current ? 'bg-accent-tint-text' : 'bg-border-strong'}`}
                    style={{ height: `${Math.max(2, (point.value / max) * 100)}%` }}
                  />
                </div>
                <span className={`text-sm ${current ? 'font-semibold text-text' : 'text-text-muted'}`}>
                  {point.label}
                </span>
              </div>
            );
          })}
        </div>
      </Card>
    </div>
  );
}
