"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { BanknoteIcon, Loader2 } from "lucide-react";
import { useForm, useWatch } from "react-hook-form";

import { Button } from "@/components/ui/button";
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
import { formatMoney } from "@/features/pos/lib/format";
import { SalePaymentStatusBadge } from "@/features/sales/components/SalePaymentStatusBadge";
import {
  SALE_PAYMENT_METHOD_OPTIONS,
  buildSalePaymentValues,
  createSalePaymentSchema,
  toApiPaymentMethodOption,
  type SalePaymentFormValues,
} from "@/features/sales/lib/salePaymentSchema";
import { saleCustomerName } from "@/features/sales/lib/sales";
import { recordPayment } from "@/features/sales/services/salesService";
import { applyValidationErrors, messageOfApiError } from "@/lib/api/validation";
import { useSalesStore } from "@/stores/useSalesStore";
import type { Sale } from "@/types/sale";

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

/** Read-only `label … value` row of the summary list. */
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
      <dd className={`text-right text-sm font-medium tabular-nums ${valueClassName ?? ""}`}>
        {value}
      </dd>
    </div>
  );
}

interface SalePaymentDialogProps {
  /** The invoice being settled. */
  sale: Sale;
  /** Called with `false` once the cashier dismisses the dialog. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Record Payment dialog — settles (part of) an outstanding invoice.
 *
 * The invoice is already chosen: it opens from `/sales/[id]`, so the form only
 * asks for what the API's `StorePaymentRequest` actually stores:
 *
 *   amount · payment_method · reference · notes
 *
 * The remaining due is **read, never recomputed** — `Sale.dueAmount` is the
 * canonical figure `SaleResource` returned, and it is what both the summary and
 * the schema's ceiling are built from.
 *
 * Submit path: `salesService.recordPayment` → `POST /sales/{id}/payments`. The
 * dialog closes only after that request resolves, so a rejected payment can
 * never look successful; the error is shown in place and the entered values
 * stay put for a retry. On success the invoice is re-read through the store's
 * `loadSale`, which is the only writer of `Sale` — the API's recalculated
 * `paid_amount`, `due_amount`, `payment_status` and the new payment row come
 * from `GET /sales/{id}`, never from a local guess.
 *
 * `isSubmitting` is React Hook Form's own in-flight flag, and the submit button
 * is disabled on it: that is the duplicate-submission guard, so a double click
 * cannot post the same payment twice.
 *
 * Stock and the cash drawer are deliberately untouched. The API posts the
 * cash-register entry itself inside the same transaction (see
 * `PaymentService::recordForSale`), so adding one here would duplicate it.
 */
export function SalePaymentDialog({ sale, onOpenChange }: SalePaymentDialogProps) {
  const loadSale = useSalesStore((state) => state.loadSale);
  const [formError, setFormError] = useState<string | null>(null);

  // The canonical outstanding amount. `SaleResource` sends `due_amount`, which
  // `toSale` maps onto `Sale.dueAmount`.
  const dueAmount = sale.dueAmount;

  const form = useForm<SalePaymentFormValues>({
    resolver: zodResolver(createSalePaymentSchema(dueAmount)),
    defaultValues: buildSalePaymentValues(dueAmount),
  });
  const { register, handleSubmit, setValue, setError, formState, control } = form;
  const { errors, isSubmitting } = formState;
  // `useWatch` (not `form.watch`) so the React Compiler can memoise the
  // component, matching `SaleReturnDialog`.
  const methodValue = useWatch({ control, name: "paymentMethod" });

  const submitPayment = handleSubmit(async (values) => {
    setFormError(null);

    // Guard inside the handler as well as on the button, so a second post is
    // impossible even if the form is submitted programmatically.
    if (isSubmitting) {
      return;
    }

    try {
      await recordPayment(sale.id, {
        amount: values.amount,
        paymentMethod: values.paymentMethod,
        reference: values.reference.trim() || null,
        notes: values.notes.trim() || null,
      });

      // The API is the source of truth: re-read the invoice so the paid
      // amount, due, status and the new payment row are the stored ones.
      await loadSale(sale.id);

      onOpenChange(false);
    } catch (error) {
      // Field-level messages land on their input; anything else (the 409
      // over-payment rule, a network failure) becomes a single banner.
      applyValidationErrors<SalePaymentFormValues>(error, setError, [
        "amount",
        "paymentMethod",
        "reference",
        "notes",
      ]);
      setFormError(messageOfApiError(error, "Could not record the payment."));
    }
  });
  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Record payment</DialogTitle>
          <DialogDescription className="font-mono">{sale.invoiceNumber}</DialogDescription>
        </DialogHeader>

        <form onSubmit={submitPayment} noValidate className="grid gap-4">
          <div className="flex flex-wrap items-center gap-2">
            <SalePaymentStatusBadge status={sale.paymentStatus} />
            <span className="text-xs text-muted-foreground">{saleCustomerName(sale)}</span>
          </div>

          {/* The figures the cashier checks the amount against. */}
          <dl className="divide-y divide-border rounded-lg border border-border bg-muted/40 px-3 py-1.5">
            <SummaryRow label="Total" value={formatMoney(sale.grandTotal)} />
            <SummaryRow label="Paid" value={formatMoney(sale.paidAmount)} />
            <SummaryRow
              label="Due"
              value={formatMoney(dueAmount)}
              valueClassName={dueAmount > 0 ? "text-destructive" : ""}
            />
          </dl>

          {formError !== null && (
            <p className="text-xs text-destructive" role="alert">
              {formError}
            </p>
          )}

          <div className="grid gap-1.5">
            <Label htmlFor="payment-amount">Amount</Label>
            <Input
              id="payment-amount"
              type="number"
              inputMode="decimal"
              min={0}
              step="0.01"
              disabled={isSubmitting || dueAmount <= 0}
              aria-invalid={errors.amount !== undefined}
              {...register("amount", { valueAsNumber: true })}
            />
            <FormError message={errors.amount?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="payment-method">Payment method</Label>
            <Select
              value={methodValue}
              onValueChange={(value) => {
                // Narrowed, not cast: only a value from the rendered options
                // can be written back onto the form.
                const method = toApiPaymentMethodOption(value ?? "");
                if (method !== null) {
                  setValue("paymentMethod", method, { shouldValidate: true });
                }
              }}
            >
              <SelectTrigger
                id="payment-method"
                aria-label="Payment method"
                aria-invalid={errors.paymentMethod !== undefined}
                disabled={isSubmitting}
                className="w-full"
              >
                {SALE_PAYMENT_METHOD_OPTIONS.find((o) => o.value === methodValue)?.label ??
                  "Select"}
              </SelectTrigger>
              <SelectContent>
                {SALE_PAYMENT_METHOD_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FormError message={errors.paymentMethod?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="payment-reference">Reference</Label>
            <Input
              id="payment-reference"
              placeholder="Cheque no., transaction id…"
              maxLength={100}
              disabled={isSubmitting}
              aria-invalid={errors.reference !== undefined}
              {...register("reference")}
            />
            <FormError message={errors.reference?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="payment-notes">Note</Label>
            <Input
              id="payment-notes"
              placeholder="Optional"
              maxLength={2000}
              disabled={isSubmitting}
              aria-invalid={errors.notes !== undefined}
              {...register("notes")}
            />
            <FormError message={errors.notes?.message} />
          </div>

          <DialogFooter>
            <DialogClose
              render={<Button type="button" variant="outline" disabled={isSubmitting} />}
            >
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting || dueAmount <= 0}>
              {isSubmitting ? (
                <>
                  <Loader2 className="animate-spin" aria-hidden="true" />
                  Recording…
                </>
              ) : (
                <>
                  <BanknoteIcon aria-hidden="true" />
                  Record payment
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}