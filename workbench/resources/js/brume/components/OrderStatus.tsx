/**
 * List cell for 訂單 → 狀態: the raw status code from the row becomes a labelled pill.
 * Status colors are the token's text colors on the row surface, so they keep 4.5:1 in both themes.
 */
const STATUSES: Record<string, { label: string; className: string }> = {
  pending: { label: '待付款', className: 'text-warning' },
  paid: { label: '已付款', className: 'text-info' },
  shipped: { label: '已出貨', className: 'text-text' },
  completed: { label: '已完成', className: 'text-success' },
  cancelled: { label: '已取消', className: 'text-danger' },
};

export function OrderStatus({ context }: { context: { row: Record<string, unknown> } }) {
  const code = String(context.row.status ?? '');
  const status = STATUSES[code] ?? { label: code, className: 'text-text-muted' };

  return (
    <span
      className={`inline-flex items-center rounded-pill border border-current px-2.5 py-0.5 text-xs font-semibold ${status.className}`}
    >
      {status.label}
    </span>
  );
}
