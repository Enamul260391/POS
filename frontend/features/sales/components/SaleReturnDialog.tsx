"use client";

import { useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, CheckCircle2, Undo2 } from "lucide-react";
import {
  Controller,
  useForm,
  useWatch,
  type FieldErrors,
  type UseFormReturn,
} from "react-hook-form";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { SalePaymentStatusBadge } from "@/features/sales/components/SalePaymentStatusBadge";
import {
  DEFAULT_RETURN_REASON,
  RETURN_REASON_LABELS,
  RETURN_REASON_OPTIONS,
  calculateReturnTotals,
  refundForQuantity,
  refundTotalForSale,
  restockedUnitCount,
  returnUnitCount,
  returnableQuantities,
} from "@/features/sales/lib/returns";
import {
  buildSaleReturnValues,
  createSaleReturnSchema,
  type SaleReturnFormValues,
} from "@/features/sales/lib/saleReturnSchema";
import { saleCustomerName } from "@/features/sales/lib/sales";
import { createSaleReturn } from "@/features/sales/services/salesService";
import { messageOfApiError } from "@/lib/api/validation";
import { formatLocalDateTime } from "@/lib/datetime";
import { selectSaleReturns, useSalesStore } from "@/stores/useSalesStore";
import type { Sale, SaleItem } from "@/types/sale";
import type { SaleReturn } from "@/types/saleReturn";
import { cn } from "cn";

/** Inline validation message rendered under a field. */
function FormError({ message }: { message?: string }) {
  if (message === undefined) {
    return null;
  }
  return (
    <p className="text-xs text-destructive" role="alert">
      {message}
    </p>
  );
}

/** Read-only `label … value` row of the summary lists. */
function SummaryRow({
  label,
  value,
  valueClassName,
}: {
  label: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1">
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className={cn("text-right text-sm font-medium tabular-nums", valueClassName)}>
        {value}
      </dd>
    </div>
  );
}
interface ReturnLineRowProps {
  index: number;
  /** Invoice line this row returns from. */
  item: SaleItem;
  /** Quantity that may still come back of this line. */
  returnable: number;
  form: UseFormReturn<SaleReturnFormValues>;
  errors: FieldErrors<SaleReturnFormValues>;
}

/**
 * One invoice line of the return form; all state lives in react-hook-form.
 *
 * Row level `useWatch` subscriptions keep typing local: changing a quantity
 * re-renders this row, not the whole list. The checkbox carries the "select
 * returned items" step, and ticking it prefills the quantity with everything
 * still returnable, so "give all of this back" is one click followed by one
 * quantity edit at most.
 */
function ReturnLineRow({ index, item, returnable, form, errors }: ReturnLineRowProps) {
  const { control, register, setValue } = form;
  const lineErrors = errors.lines?.[index];

  const returned = useWatch({ control, name: `lines.${index}.returned` }) ?? false;
  const quantity = useWatch({ control, name: `lines.${index}.quantity` });
  const refund = returned && Number.isFinite(quantity) ? refundForQuantity(item, quantity) : 0;
  const fullyReturned = returnable <= 0;

  return (
    <div
      className={cn(
        "grid gap-2 rounded-lg border border-border p-2.5 transition-colors",
        returned && "border-primary/40 bg-primary/5",
      )}
    >
      <div className="flex items-start gap-2">
        <Controller
          control={control}
          name={`lines.${index}.returned`}
          render={({ field }) => (
            <Checkbox
              id={`lines.${index}.returned`}
              className="mt-0.5"
              checked={field.value}
              disabled={fullyReturned}
              onCheckedChange={(checked) => {
                field.onChange(checked);
                if (checked && !(Number.isFinite(quantity) && quantity > 0)) {
                  setValue(`lines.${index}.quantity`, returnable, { shouldDirty: true });
                }
              }}
            />
          )}
        />

        <div className="min-w-0 flex-1">
          <label
            htmlFor={`lines.${index}.returned`}
            className="flex items-center gap-1.5"
          >
            <span className="truncate text-sm font-medium" title={item.productName}>
              {item.productName}
            </span>
            {fullyReturned && <Badge variant="secondary">Fully returned</Badge>}
          </label>
          <p className="text-xs text-muted-foreground">
            {item.sku} · sold {formatQuantity(item.quantity)} × {formatMoney(item.unitPrice)} ·{" "}
            {formatMoney(item.lineTotal)}
          </p>
          <p className="text-xs text-muted-foreground">
            Returnable {formatQuantity(returnable)} {item.unit}
          </p>
        </div>

        <span className="shrink-0 text-sm font-semibold tabular-nums">
          {returned ? formatMoney(refund) : "—"}
        </span>
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        <div className="grid gap-1.5">
          <Label htmlFor={`lines.${index}.quantity`}>Return quantity</Label>
          <Input
            id={`lines.${index}.quantity`}
            type="number"
            inputMode="decimal"
            min={0}
            step="1"
            disabled={!returned}
            aria-invalid={lineErrors?.quantity !== undefined}
            {...register(`lines.${index}.quantity`, { valueAsNumber: true })}
          />
          <FormError message={lineErrors?.quantity?.message} />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor={`lines.${index}.reason`}>Return reason</Label>
          <Controller
            control={control}
            name={`lines.${index}.reason`}
            render={({ field }) => {
              const selected = RETURN_REASON_OPTIONS.find(
                (option) => option.value === field.value,
              );
              return (
                <Select
                  value={field.value}
                  disabled={!returned}
                  onValueChange={(value) => {
                    const reason = (value ??
                      field.value) as SaleReturnFormValues["lines"][number]["reason"];
                    field.onChange(reason);
                    // `restock` stays `true` for every row: the API restores
                    // stock for each returned line, so the form never lets a
                    // cashier set a flag the backend would ignore.
                  }}
                >
                  <SelectTrigger
                    id={`lines.${index}.reason`}
                    className="w-full"
                    aria-invalid={lineErrors?.reason !== undefined}
                  >
                    <span>{selected?.label ?? "Reason"}</span>
                  </SelectTrigger>
                  <SelectContent>
                    {RETURN_REASON_OPTIONS.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              );
            }}
          />
          <FormError message={lineErrors?.reason?.message} />
        </div>
      </div>

      {/* The API restores stock for every returned line, so the row states the
          fact instead of offering a checkbox it would ignore. */}
      <p className="text-xs text-muted-foreground">
        {returned
          ? "Put back into stock by the server when the return is recorded."
          : "Tick the line to take it back into stock."}
      </p>
    </div>
  );
}
interface SaleReturnDialogProps {
  sale: Sale;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/** A ticked row of the form, resolved against the invoice line it belongs to. */
interface SelectedReturnLine {
  item: SaleItem;
  quantity: number;
  reason: SaleReturnFormValues["lines"][number]["reason"];
  restock: boolean;
}

/**
 * The loose row shape react-hook-form hands out while typing (`useWatch`
 * returns deep partial values); `selectReturnLines` narrows it back.
 */
interface ReturnFormLineLike {
  saleItemId?: number | undefined;
  returned?: boolean | undefined;
  quantity?: number | undefined;
  reason?: SaleReturnFormValues["lines"][number]["reason"] | undefined;
  restock?: boolean | undefined;
}

/**
 * Resolves the ticked form rows against the invoice lines they belong to.
 *
 * Rows that are not ticked, carry no quantity or point at a vanished line are
 * dropped, so the live preview and the confirmation list always see exactly
 * the selection the submit would send.
 */
function selectReturnLines(
  sale: Sale,
  lines: readonly ReturnFormLineLike[] | undefined,
): SelectedReturnLine[] {
  const itemById = new Map(sale.items.map((item) => [item.id, item]));
  const selected: SelectedReturnLine[] = [];

  for (const line of lines ?? []) {
    const item = line.saleItemId === undefined ? undefined : itemById.get(line.saleItemId);
    const quantity = line.quantity;
    if (
      item === undefined ||
      line.returned !== true ||
      quantity === undefined ||
      !Number.isFinite(quantity) ||
      quantity <= 0
    ) {
      continue;
    }
    selected.push({
      item,
      quantity,
      reason: line.reason ?? DEFAULT_RETURN_REASON,
      restock: line.restock === true,
    });
  }

  return selected;
}

/**
 * Builds the single free-text reason the API stores.
 *
 * `sale_returns.reason` is one column for the whole return, while the dialog
 * collects a reason per line; the distinct labels are joined with `, ` and the
 * optional note is appended after an em dash, so nothing the cashier typed is
 * lost. The column is capped at 2000 characters by `StoreSaleReturnRequest`,
 * which the trailing slice respects.
 */
function composeReturnReason(
  lines: readonly { reason: SaleReturnFormValues["lines"][number]["reason"] }[],
  note: string,
): string {
  const labels: string[] = [];
  for (const line of lines) {
    const label = RETURN_REASON_LABELS[line.reason];
    if (!labels.includes(label)) {
      labels.push(label);
    }
  }

  const trimmedNote = note.trim();
  const composed = [labels.join(", "), trimmedNote].filter((part) => part !== "").join(" — ");
  return composed.slice(0, 2000);
}

/**
 * Return dialog (Phase 10) — "select sale → select items → confirm".
 *
 * The sale is already chosen (the dialog opens from an invoice row or the
 * invoice screen), so it walks the remaining steps:
 *
 *   1. select items   tick the lines coming back with quantity, reason and
 *                     whether the goods go back on the shelf; the refund is
 *                     previewed per line and in the totals panel
 *   2. confirmation   a review of exactly what was selected plus refund method
 *                     and note — nothing is written before "Confirm return"
 *
 * Validation is React Hook Form + Zod (`createSaleReturnSchema`), closed over
 * what is still returnable per line, so an over-return cannot be submitted.
 * The submit calls `createSaleReturn` — the future
 * `POST /api/sales/{sale}/returns` boundary — which validates again, reverses
 * stock through the Phase 7 `adjustStock` boundary for restocked lines and
 * records the return. The dialog itself never touches stock or a store, and
 * `calculateReturnTotals` is shared with the service, so the confirmed refund
 * is the stored refund. Mounted only while open, so every return starts clean.
 */
export function SaleReturnDialog({ sale, onOpenChange }: SaleReturnDialogProps) {
  const saleReturns = useSalesStore(selectSaleReturns);

  // Every line is capped by the returns already recorded for this invoice.
  const returnableByItemId = useMemo(
    () => returnableQuantities(sale, saleReturns),
    [sale, saleReturns],
  );
  const refundedSoFar = useMemo(
    () => refundTotalForSale(saleReturns, sale.id),
    [saleReturns, sale.id],
  );

  const form = useForm<SaleReturnFormValues>({
    resolver: zodResolver(createSaleReturnSchema(returnableByItemId)),
    defaultValues: buildSaleReturnValues(sale, returnableByItemId),
  });
  const { control, handleSubmit, register, formState } = form;
  const { errors, isSubmitting } = formState;

  const [step, setStep] = useState<"select" | "confirm">("select");
  const [reviewedValues, setReviewedValues] = useState<SaleReturnFormValues | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<SaleReturn | null>(null);

  // Live preview of the current selection.
  const watched = useWatch({ control });
  const selectedLines = useMemo(
    () => selectReturnLines(sale, watched.lines),
    [sale, watched.lines],
  );
  const totals = useMemo(() => calculateReturnTotals(selectedLines), [selectedLines]);

  // Frozen snapshot of the reviewed selection: the confirmation shows what the
  // cashier saw, not whatever the form holds afterwards.
  const reviewedLines = useMemo(
    () => (reviewedValues === null ? [] : selectReturnLines(sale, reviewedValues.lines)),
    [sale, reviewedValues],
  );
  const reviewedTotals = useMemo(
    () => calculateReturnTotals(reviewedLines),
    [reviewedLines],
  );

  const review = handleSubmit((values) => {
    setFormError(null);
    setReviewedValues(values);
    setStep("confirm");
  });

  const submitReturn = handleSubmit(async (values) => {
    setFormError(null);
    const selected = values.lines.flatMap((line) =>
      line.returned
        ? [{ saleItemId: line.saleItemId, quantity: line.quantity, reason: line.reason }]
        : [],
    );

    try {
      const saleReturn = await createSaleReturn({
        saleId: sale.id,
        lines: selected.map((line) => ({
          saleItemId: line.saleItemId,
          quantity: line.quantity,
        })),
        // `sale_returns.reason` is a single free-text column, so the per-line
        // reasons are composed into it (the note is appended when given).
        reason: composeReturnReason(selected, values.note),
      });

      // The API already reversed stock inside its own transaction; the store
      // only has to remember the document it answered with.
      useSalesStore.getState().addSaleReturn(saleReturn);
      setConfirmation(saleReturn);
    } catch (error) {
      // The fix is almost always a quantity, so send the cashier back there.
      setFormError(messageOfApiError(error, "Could not record the return."));
      setStep("select");
    }
  });

  if (confirmation !== null) {
    return (
      <Dialog open onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Return recorded</DialogTitle>
            <DialogDescription>
              {confirmation.invoiceNumber} · {confirmation.reference}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col items-center gap-1.5 py-1 text-center">
            <CheckCircle2 className="size-9 text-success" aria-hidden="true" />
            <p className="font-heading text-base font-semibold">
              {formatMoney(confirmation.refundTotal)} refunded
            </p>
            <p className="text-xs text-muted-foreground">
              {formatQuantity(returnUnitCount(confirmation))} units taken back ·{" "}
              {formatQuantity(restockedUnitCount(confirmation))} restocked
            </p>
          </div>

          <dl className="divide-y divide-border">
            <SummaryRow label="Return number" value={confirmation.reference} />
            <SummaryRow label="Invoice" value={confirmation.invoiceNumber} />
            <SummaryRow
              label="Returned at"
              value={formatLocalDateTime(confirmation.returnedAt)}
            />
          </dl>

          {/* Stock was already reversed by the API inside the same transaction,
              so the dialog reports it instead of offering a choice. */}
          <p className="text-xs text-muted-foreground">
            The returned quantities were put back into stock by the server. No
            cash-drawer refund was recorded — the API does not post one.
          </p>

          <div className="flex justify-end">
            <Button type="button" onClick={() => onOpenChange(false)}>
              Done
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    );
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl">
        <form
          onSubmit={step === "select" ? review : submitReturn}
          noValidate
          className="grid gap-4"
        >
          <DialogHeader>
            <DialogTitle className="font-mono">{sale.invoiceNumber}</DialogTitle>
            <DialogDescription>
              {saleCustomerName(sale)} ·{" "}
              <span suppressHydrationWarning>{formatLocalDateTime(sale.soldAt)}</span> ·{" "}
              {formatMoney(sale.grandTotal)}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-wrap items-center gap-2">
            <SalePaymentStatusBadge status={sale.paymentStatus} />
            {refundedSoFar > 0 && (
              <Badge variant="secondary">Refunded so far {formatMoney(refundedSoFar)}</Badge>
            )}
          </div>

          {formError !== null && (
            <p className="text-xs text-destructive" role="alert">
              {formError}
            </p>
          )}

          {step === "select" ? (
            <>
              <div>
                <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  Select returned items
                </h3>
                <div className="grid max-h-[45dvh] gap-2 overflow-y-auto pr-1">
                  {sale.items.map((item, index) => (
                    <ReturnLineRow
                      key={item.id}
                      index={index}
                      item={item}
                      returnable={returnableByItemId.get(item.id) ?? 0}
                      form={form}
                      errors={errors}
                    />
                  ))}
                </div>
                {totals.lines === 0 && (
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    Tick the items the customer brought back to continue.
                  </p>
                )}
              </div>

              <div className="grid gap-1 rounded-lg border border-border bg-muted/40 p-2.5">
                <SummaryRow label="Lines coming back" value={String(totals.lines)} />
                <SummaryRow
                  label="Units coming back"
                  value={formatQuantity(totals.quantities)}
                />
                <SummaryRow
                  label="Back into stock"
                  value={formatQuantity(totals.restockedQuantities)}
                />
                <SummaryRow
                  label="Refund amount"
                  value={formatMoney(totals.refundTotal)}
                  valueClassName="font-heading font-semibold"
                />
              </div>

              <DialogFooter>
                <DialogClose render={<Button type="button" variant="outline" />}>
                  Cancel
                </DialogClose>
                <Button type="submit" disabled={isSubmitting || totals.lines === 0}>
                  Review return · {formatMoney(totals.refundTotal)}
                </Button>
              </DialogFooter>
            </>
          ) : (
            <>
              <div>
                <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  Confirm the return
                </h3>
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                  {reviewedLines.map((line) => (
                    <li
                      key={line.item.id}
                      className="flex items-start justify-between gap-3 p-2.5"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium" title={line.item.productName}>
                          {line.item.productName}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {formatQuantity(line.quantity)} × {formatMoney(line.item.unitPrice)} ·{" "}
                          {RETURN_REASON_LABELS[line.reason]} ·{" "}
                          {line.restock ? "back into stock" : "not restocked"}
                        </p>
                      </div>
                      <span className="shrink-0 text-sm font-semibold tabular-nums">
                        {formatMoney(refundForQuantity(line.item, line.quantity))}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>

              <div className="grid gap-2 sm:grid-cols-2">
                <div className="grid gap-1.5">
                  <Label htmlFor="note">Note</Label>
                  <Input
                    id="note"
                    placeholder="Return slip no."
                    aria-invalid={errors.note !== undefined}
                    {...register("note")}
                  />
                  <FormError message={errors.note?.message} />
                </div>
              </div>

              <div className="grid gap-1 rounded-lg border border-border bg-muted/40 p-2.5">
                <SummaryRow
                  label={`Refund total (${reviewedTotals.lines} lines · ${formatQuantity(reviewedTotals.quantities)} units)`}
                  value={formatMoney(reviewedTotals.refundTotal)}
                  valueClassName="font-heading font-semibold"
                />
                <SummaryRow
                  label="Back into stock"
                  value={`${formatQuantity(reviewedTotals.restockedQuantities)} units`}
                />
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setStep("select")}>
                  <ArrowLeft aria-hidden="true" />
                  Back to items
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  <Undo2 aria-hidden="true" />
                  Confirm return · {formatMoney(reviewedTotals.refundTotal)}
                </Button>
              </DialogFooter>
            </>
          )}
        </form>
      </DialogContent>
    </Dialog>
  );
}
