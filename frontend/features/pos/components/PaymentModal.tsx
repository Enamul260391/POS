"use client";

import { useCallback, useRef, useState } from "react";
import {
  Banknote,
  CheckCircle2,
  CreditCard,
  Loader2,
  Printer,
  Smartphone,
  type LucideIcon,
} from "lucide-react";
import { useReactToPrint } from "react-to-print";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { buildSaleInput } from "@/features/pos/lib/checkout";
import { formatMoney, parseAmount } from "@/features/pos/lib/format";
import { ThermalReceipt } from "@/features/receipt/components/ThermalReceipt";
import { useReceiptSettings } from "@/features/receipt/hooks/useReceiptSettings";
import {
  buildReceiptSale,
  type BuildReceiptSaleInput,
} from "@/features/receipt/lib/receipt";
import { createSale } from "@/features/sales/services/salesService";
import { useAuthStore } from "@/stores/useAuthStore";
import { selectCartTotals, useCartStore, useCartTotals } from "@/stores/useCartStore";
import { useSalesStore } from "@/stores/useSalesStore";
import type { ApiError } from "@/types/api";
import type { PaymentMethod } from "@/types/payment";
import type { Sale } from "@/types/sale";
import { cn } from "cn";

interface PaymentModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type PaymentChoice = "cash" | "bkash" | "card" | "nagad";

interface PaymentMethodOption {
  id: PaymentChoice;
  label: string;
  /** Maps straight onto `PaymentMethod`, so a future API payload needs no translation. */
  method: PaymentMethod;
  /** Provider name for mobile banking (bKash, Nagad), else `null`. */
  provider: string | null;
  icon: LucideIcon;
}

const CASH_OPTION: PaymentMethodOption = {
  id: "cash",
  label: "Cash",
  method: "cash",
  provider: null,
  icon: Banknote,
};

const PAYMENT_METHODS: readonly PaymentMethodOption[] = [
  CASH_OPTION,
  { id: "bkash", label: "bKash", method: "mobile_banking", provider: "bKash", icon: Smartphone },
  { id: "card", label: "Card", method: "card", provider: null, icon: CreditCard },
  { id: "nagad", label: "Nagad", method: "mobile_banking", provider: "Nagad", icon: Smartphone },
];

function resolveMethod(id: PaymentChoice): PaymentMethodOption {
  return PAYMENT_METHODS.find((option) => option.id === id) ?? CASH_OPTION;
}

/**
 * Snapshot of a sale the API has confirmed.
 *
 * `reference` is the invoice number the **backend** generated and returned, not
 * a locally invented string. `sale` is the same authoritative document, kept so
 * the receipt prints the persisted figures rather than a second, possibly
 * different, set derived from the cart.
 */
interface PaymentConfirmation {
  reference: string;
  methodLabel: string;
  provider: string | null;
  amountDue: number;
  received: number;
  change: number;
  sale: Sale;
}

/**
 * Picks the most useful message out of a rejected checkout.
 *
 * `apiClient` normalises every failure into `ApiError` (see `@/lib/axios`), so
 * the `message` is already display-safe. A `422` names the offending field, and
 * a business-rule rejection (insufficient stock, paid amount over the total)
 * arrives as a `409`/`422` with the API's own wording — both are worth showing
 * verbatim, because they tell the cashier what to fix.
 */
function checkoutErrorMessage(error: unknown): string {
  if (
    typeof error === "object" &&
    error !== null &&
    "message" in error &&
    typeof (error as { message: unknown }).message === "string" &&
    (error as { message: string }).message.trim() !== ""
  ) {
    return (error as ApiError).message;
  }
  return "The sale could not be recorded. The cart has been kept — please try again.";
}

function DetailRow({
  term,
  value,
  valueClassName,
}: {
  term: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <dt className="text-muted-foreground">{term}</dt>
      <dd className={cn("font-medium tabular-nums", valueClassName)}>{value}</dd>
    </div>
  );
}

/**
 * Checkout dialog: method → amount due → received → change → confirm.
 *
 * The sale is **persisted**: confirming calls `createSale()` (`POST /sales`),
 * and the API writes the sale, its lines, the payment and the stock movements
 * in one transaction. Everything on the confirmation screen — the invoice
 * number, the total, the receipt — comes from that response, so the screen can
 * only ever report a sale that genuinely exists in the database.
 *
 * Failure is the important half of the contract. The cart is cleared *last* and
 * only after the API has answered, so a rejection (validation, business rule,
 * 401/403, network) leaves the cart exactly as the cashier built it, shows the
 * API's own message, and offers a plain retry. `isSaving` disables the confirm
 * action for the duration, so a double click cannot record two sales.
 *
 * Cash pre-fills the exact total, so an exact cash sale is: checkout → confirm —
 * minimum clicks at the till.
 *
 * Shell vs flow: `PaymentModal` only wires `open` to the shadcn Dialog; all
 * flow state lives in `PaymentFlow`, which is rendered inside the dialog
 * popup. The popup unmounts when the dialog closes, so reopening always starts
 * from fresh state (default method, pre-filled amount, no leftover
 * confirmation, no stale error) — reset by remount instead of a synchronising
 * effect.
 *
 * Method choices are native radios (full keyboard support: arrows, focus
 * ring) styled through the `peer-checked` variant — no hand-rolled ARIA.
 */
export function PaymentModal({ open, onOpenChange }: PaymentModalProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-md">
        <PaymentFlow onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  );
}

function PaymentFlow({ onDone }: { onDone: () => void }) {
  const totals = useCartTotals();
  const [methodId, setMethodId] = useState<PaymentChoice>("cash");
  // Prefilled at mount (dialog opened): exact-cash confirm is a single click.
  const [receivedText, setReceivedText] = useState(() =>
    selectCartTotals(useCartStore.getState()).grandTotal.toFixed(2),
  );
  const [confirmation, setConfirmation] = useState<PaymentConfirmation | null>(null);
  // The confirmed sale's receipt inputs, snapshotted before `clearCart()`.
  // Held as inputs rather than a built `ReceiptSale` so the receipt can be
  // re-derived when the Settings request settles: a shop name that arrives a
  // moment after checkout must still reach the paper.
  const [receiptInput, setReceiptInput] = useState<BuildReceiptSaleInput | null>(null);
  // Saving state doubles as the double-submit guard: the confirm button is
  // disabled while a request is in flight, and `handleConfirm` re-checks it,
  // so an impatient double click cannot create two sales.
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Printed shop identity, read once through the existing Settings service
  // while the dialog is open. A failure leaves the block empty — the receipt
  // then simply omits those lines instead of inventing them.
  const {
    settings: receiptSettings,
    isLoading: isLoadingReceiptSettings,
    error: receiptSettingsError,
  } = useReceiptSettings();
  const cashierName = useAuthStore((state) => state.user?.name ?? null);

  const receiptRef = useRef<HTMLElement>(null);
  const printReceipt = useReactToPrint({
    contentRef: receiptRef,
    documentTitle: () => receiptSale?.invoiceNumber ?? "receipt",
  });

  const amountDue = totals.grandTotal;
  const isCash = methodId === "cash";
  const received = isCash ? parseAmount(receivedText) : amountDue;
  const isCovered = received + 0.001 >= amountDue;
  const change = isCovered ? Math.max(0, received - amountDue) : 0;
  const shortfall = isCovered ? 0 : amountDue - received;
  const canConfirm =
    confirmation === null && !isSaving && amountDue > 0 && isCovered;
  const selected = resolveMethod(methodId);

  const handleConfirm = useCallback(async () => {
    if (!canConfirm) {
      return;
    }

    const store = useCartStore.getState();
    setIsSaving(true);
    setError(null);

    try {
      // The cart still holds every line at this point, so the snapshot taken
      // just before the request is guaranteed to describe the sale the cashier
      // is paying for — including when the request is retried.
      const cartItems = store.items;
      const cartTotals = selectCartTotals(store);
      const soldAt = new Date();

      // `paidAmount` is what settles the *invoice*, not the cash handed over:
      // the difference is change, which the API neither stores nor can accept
      // (a `paid_amount` above the grand total is rejected). So the invoice is
      // always settled for exactly `amountDue`, and `received`/`change` stay a
      // purely local, display-only pair.
      const sale = await createSale(
        buildSaleInput({
          totals: cartTotals,
          customerId: store.customerId,
          method: selected.method,
          paid: { grandTotal: cartTotals.grandTotal, paidAmount: cartTotals.grandTotal },
          now: soldAt,
        }),
      );

      // Only a real API response reaches this point. Everything below is
      // therefore reporting a sale that genuinely exists in the database.
      useSalesStore.getState().cacheSale(sale);

      setReceiptInput({
        items: cartItems,
        totals: cartTotals,
        payment: { methodLabel: selected.label, paidAmount: sale.paidAmount, change },
        customerName: sale.customerName ?? store.customerName,
        // Print the invoice number and totals the API committed to, so the
        // paper receipt always matches the stored sale.
        invoiceNumber: sale.invoiceNumber,
        cashierName,
        soldAt,
      });
      setConfirmation({
        reference: sale.invoiceNumber,
        methodLabel: selected.label,
        provider: selected.provider,
        amountDue: sale.grandTotal,
        received,
        change,
        sale,
      });

      // Last step, and only on success: a failed request must leave the cart
      // exactly as it was so the cashier can retry without rebuilding it.
      store.clearCart();
    } catch (cause: unknown) {
      // No receipt, no confirmation, no cached sale and no `clearCart()`.
      setError(checkoutErrorMessage(cause));
    } finally {
      setIsSaving(false);
    }
  }, [canConfirm, selected, received, change, cashierName]);

  // Built from the snapshot plus the business block currently on screen, so the
  // receipt picks the Settings up whether they loaded before or after checkout.
  const receiptSale =
    receiptInput === null ? null : buildReceiptSale(receiptInput, receiptSettings);

  const handleDone = () => {
    // Closing unmounts this flow, which resets every piece of local state.
    onDone();
  };

  return (
    <>
      <DialogHeader>
        <DialogTitle>
          {confirmation === null ? "Payment" : "Payment confirmed"}
        </DialogTitle>
        <DialogDescription>
          {confirmation === null
            ? "Confirm to record the sale against the current cart."
            : `Sale ${confirmation.reference} recorded.`}
        </DialogDescription>
      </DialogHeader>

      {confirmation === null ? (
        <div className="space-y-3">
          <fieldset className="space-y-1.5">
            <legend className="text-sm font-medium">Payment method</legend>
            <div className="grid grid-cols-4 gap-1.5">
              {PAYMENT_METHODS.map((option) => {
                const Icon = option.icon;
                return (
                  <label key={option.id} className="relative cursor-pointer">
                    <input
                      type="radio"
                      name="pos-payment-method"
                      value={option.id}
                      checked={methodId === option.id}
                      onChange={() => setMethodId(option.id)}
                      className="peer sr-only"
                    />
                    <span className="flex h-16 flex-col items-center justify-center gap-1 rounded-lg border border-border bg-background text-xs font-medium transition-colors peer-checked:border-primary peer-checked:bg-primary/10 peer-focus-visible:ring-2 peer-focus-visible:ring-ring/70">
                      <Icon className="size-4" aria-hidden="true" />
                      {option.label}
                    </span>
                  </label>
                );
              })}
            </div>
          </fieldset>

          <div className="flex items-center justify-between rounded-lg bg-muted/60 px-3 py-2">
            <span className="text-sm text-muted-foreground">Amount due</span>
            <span className="font-heading text-lg font-bold tabular-nums">
              {formatMoney(amountDue)}
            </span>
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor="amount-received">Amount received</Label>
              {isCash && (
                <Button
                  type="button"
                  variant="outline"
                  size="xs"
                  onClick={() => setReceivedText(amountDue.toFixed(2))}
                >
                  Exact
                </Button>
              )}
            </div>
            <Input
              id="amount-received"
              type="number"
              min={0}
              step={0.01}
              inputMode="decimal"
              className="text-right tabular-nums"
              value={isCash ? receivedText : amountDue.toFixed(2)}
              readOnly={!isCash}
              autoFocus={isCash}
              onChange={(event) => setReceivedText(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter" && canConfirm) {
                  event.preventDefault();
                  void handleConfirm();
                }
              }}
            />
          </div>

          <div className="flex items-center justify-between gap-2">
            <span className="text-sm text-muted-foreground">Change</span>
            <span
              className={cn(
                "font-heading text-lg font-bold tabular-nums",
                change > 0 ? "text-success" : "text-foreground",
              )}
            >
              {formatMoney(change)}
            </span>
          </div>

          {shortfall > 0 && (
            <p role="alert" className="text-xs text-destructive">
              {formatMoney(shortfall)} short — received amount is below the
              amount due.
            </p>
          )}

          {/*
            A failed checkout is reported in place: the modal stays open on the
            payment step and the cart is untouched, so the cashier can correct
            the cause (or simply retry a dropped connection) without rebuilding
            the sale.
          */}
          {error !== null && (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            >
              {error}
            </p>
          )}

          <Button
            type="button"
            size="lg"
            className="w-full"
            onClick={() => void handleConfirm()}
            disabled={!canConfirm}
          >
            {isSaving ? (
              <>
                <Loader2 className="animate-spin" aria-hidden="true" />
                Recording sale…
              </>
            ) : (
              <>
                <CheckCircle2 aria-hidden="true" />
                Confirm payment · {formatMoney(amountDue)}
              </>
            )}
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-col items-center gap-1.5 py-1 text-center">
            <CheckCircle2 className="size-9 text-success" aria-hidden="true" />
            <p className="font-heading text-base font-semibold">
              {formatMoney(confirmation.amountDue)} received
            </p>
          </div>
          <dl className="space-y-1.5">
            <DetailRow term="Reference" value={confirmation.reference} />
            <DetailRow
              term="Method"
              value={confirmation.provider ?? confirmation.methodLabel}
            />
            <DetailRow term="Amount due" value={formatMoney(confirmation.amountDue)} />
            <DetailRow term="Received" value={formatMoney(confirmation.received)} />
            <DetailRow
              term="Change"
              value={formatMoney(confirmation.change)}
              valueClassName="text-success"
            />
          </dl>

          {/* Screen-only preview chrome; only the ThermalReceipt below prints. */}
          {receiptSale !== null && (
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-muted-foreground">
                Receipt preview · 80 mm thermal
              </p>
              <div className="flex max-h-[40vh] items-start justify-center overflow-y-auto rounded-lg border border-border bg-background p-2">
                <ThermalReceipt ref={receiptRef} sale={receiptSale} />
              </div>
              {/*
                Business details are optional. While they load the receipt simply
                has no header yet, and a failure is reported next to the preview —
                never replaced by placeholder shop data, and never a reason to
                withhold the confirmed sale.
              */}
              {isLoadingReceiptSettings && (
                <p className="text-xs text-muted-foreground">
                  Loading business details for the receipt…
                </p>
              )}
              {receiptSettingsError !== null && (
                <p className="text-xs text-muted-foreground">
                  Business details could not be loaded ({receiptSettingsError}); the
                  receipt prints the sale only.
                </p>
              )}
            </div>
          )}
          <Button
            type="button"
            size="lg"
            variant="outline"
            className="w-full"
            onClick={printReceipt}
          >
            <Printer aria-hidden="true" />
            Print receipt
          </Button>
          <Button type="button" size="lg" className="w-full" onClick={handleDone}>
            Start new sale
          </Button>
        </div>
      )}
    </>
  );
}