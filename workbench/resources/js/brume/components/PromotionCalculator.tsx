import { Alert, Badge, Field, TextInput } from "@brume-ui/brume-react";
import { useRemoteOptions, type FieldProps, type FormApi } from "@brume-ui/brume-renderer";
import type { RemoteOptions, RowContext } from "@brume-ui/brume-schema";
import { useEffect, useState } from "react";
import {
  previewPromotion,
  type PreviewActionResult,
  type PreviewCondition,
} from "./promotion-preview";

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === "object" ? (value as Record<string, unknown>) : {};
}

/** Reads the live form value defensively: rows may be half-filled while the editor types. */
function readConditions(values: Record<string, unknown>): PreviewCondition[] {
  const rows = Array.isArray(values.conditions) ? values.conditions : [];
  return rows.map((row) => {
    const condition = asRecord(row);
    const amount = condition.amount;
    const actions = Array.isArray(condition.actions) ? condition.actions : [];
    return {
      amount: typeof amount === "string" ? amount : null,
      actions: actions.map((item) => {
        const action = asRecord(item);
        const freebies = Array.isArray(action.freebies) ? action.freebies : [];
        const count = action.choose_count;
        return {
          strategy: action.strategy === "choose" ? "choose" : "all",
          choose_count: typeof count === "number" ? count : null,
          freebies: freebies.flatMap((freebie) => {
            const id = asRecord(freebie).id;
            return typeof id === "string" || typeof id === "number" ? [{ id: String(id) }] : [];
          }),
        };
      }),
    };
  });
}

/** Product names for one action; a stable child so each picker source keeps its own request state. */
function GiftNames({
  form,
  path,
  remote,
  rowContext,
  ids,
}: {
  form: FormApi;
  path: string;
  remote: RemoteOptions | undefined;
  rowContext: RowContext | undefined;
  ids: string[];
}) {
  const source = useRemoteOptions({ remote, form, path, rowContext });
  const key = ids.join(",");
  const [labels, setLabels] = useState<Record<string, string>>({});
  useEffect(() => {
    if (ids.length === 0 || !source.enabled) return;
    let current = true;
    source
      .resolveLabels(ids)
      .then((items) => {
        if (current) setLabels(Object.fromEntries(items.map((item) => [String(item.value), item.label])));
      })
      .catch(() => {
        if (current) setLabels({});
      });
    return () => {
      current = false;
    };
    // `source` is a new object every render; its identity is `scopeKey`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, source.scopeKey, source.enabled]);
  return (
    <>
      {ids.map((id, index) => (
        <span key={id}>
          {index > 0 ? "、" : null}
          {labels[id] ?? `#${id}`}
        </span>
      ))}
    </>
  );
}

/**
 * Local scratch input: it never calls onChange. `displayOnly` fields arrive with readOnly=true, which
 * must not lock this input (it edits no form data), so only `disabled` is honoured.
 */
export function PromotionCalculator({ def, form, id, disabled }: FieldProps) {
  const [amount, setAmount] = useState("");
  const conditions = readConditions(form.values);
  const result = previewPromotion(amount.trim(), conditions);
  const resultId = `${id}-result`;

  const renderAction = (conditionIndex: number, action: PreviewActionResult) => {
    const path = `conditions.${conditionIndex}.actions.${action.actionIndex}.freebies`;
    const field = form.getField(path);
    const remote = (field?.def as { remote?: RemoteOptions } | undefined)?.remote;
    if (action.invalid) {
      return (
        <li key={action.actionIndex}>
          <Badge tone="danger">設定有誤</Badge> 任選數量必須介於 1 與贈品數（{action.productIds.length}）之間
        </li>
      );
    }
    if (action.productIds.length === 0) {
      return <li key={action.actionIndex}>尚未選擇贈品</li>;
    }
    return (
      <li key={action.actionIndex}>
        {action.kind === "choose" ? `任選 ${action.count} 樣：` : "全部送："}
        <GiftNames form={form} path={path} remote={remote} rowContext={field?.rowContext} ids={action.productIds} />
      </li>
    );
  };

  return (
    <div className="flex flex-col gap-3">
      <Field label={def.label} description={def.help ?? "輸入訂單金額，依目前表單內容試算可得的贈品；不會儲存。"}>
        <TextInput
          id={`${id}-amount`}
          inputMode="decimal"
          autoComplete="off"
          placeholder="例如 1500"
          value={amount}
          disabled={disabled}
          aria-describedby={resultId}
          onChange={(event) => setAmount(event.target.value)}
        />
      </Field>
      <div id={resultId} aria-live="polite" className="text-sm">
        {amount.trim() === "" ? null : "error" in result ? (
          <Alert tone="warning" role="status">
            請輸入有效金額（最多兩位小數）
          </Alert>
        ) : result.thresholds.length === 0 ? (
          <p>NT$ {amount.trim()} 未達任何門檻</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {result.thresholds.map((threshold) => (
              <li key={threshold.conditionIndex}>
                <strong>滿 NT$ {threshold.amount}</strong>
                <ul className="mt-1 list-disc pl-5">
                  {threshold.actions.length === 0 ? <li>尚未設定贈送方式</li> : null}
                  {threshold.actions.map((action) => renderAction(threshold.conditionIndex, action))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
