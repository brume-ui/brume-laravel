import { canonicalDecimal } from "@brume-ui/brume-react";

export interface PreviewFreebie {
  id: string;
}
export interface PreviewAction {
  strategy: string;
  choose_count: number | null;
  freebies: PreviewFreebie[];
}
export interface PreviewCondition {
  amount: string | null;
  actions: PreviewAction[];
}
/** `actionIndex` is the position in the source condition, so callers can address its form path. */
export type PreviewActionResult =
  | { kind: "all"; actionIndex: number; productIds: string[]; invalid: boolean }
  | { kind: "choose"; actionIndex: number; count: number | null; productIds: string[]; invalid: boolean };
export interface PreviewThreshold {
  conditionIndex: number;
  amount: string;
  actions: PreviewActionResult[];
}
export type PreviewResult = { thresholds: PreviewThreshold[] } | { error: "amount" };

/** Integer minor units (cents) of a canonical two-decimal amount; null when empty, negative or malformed. */
function toCents(value: string | null | undefined): bigint | null {
  // canonicalDecimal rounds extra digits; a typed 12.345 must be rejected, not silently become 12.35.
  if (typeof value !== "string" || !/^\d+(\.\d{0,2})?$/.test(value)) return null;
  const canonical = canonicalDecimal(value, 2);
  if (canonical === null || canonical === "" || canonical.startsWith("-")) return null;
  return BigInt(canonical.replace(".", ""));
}

function previewAction(action: PreviewAction, actionIndex: number): PreviewActionResult {
  const productIds = action.freebies.map((freebie) => freebie.id);
  if (action.strategy !== "choose") return { kind: "all", actionIndex, productIds, invalid: false };
  const count = action.choose_count;
  const invalid = count === null || !Number.isInteger(count) || count < 1 || count > productIds.length;
  return { kind: "choose", actionIndex, count, productIds, invalid };
}

/** Every threshold the order amount reaches, in declared order; exact integer comparison, no floats. */
export function previewPromotion(orderAmount: string, conditions: PreviewCondition[]): PreviewResult {
  const order = toCents(orderAmount);
  if (order === null) return { error: "amount" };
  const thresholds: PreviewThreshold[] = [];
  for (const [conditionIndex, condition] of conditions.entries()) {
    const cents = toCents(condition.amount);
    if (cents === null || order < cents) continue;
    thresholds.push({
      conditionIndex,
      amount: canonicalDecimal(condition.amount as string, 2) as string,
      actions: condition.actions.map(previewAction),
    });
  }
  return { thresholds };
}
