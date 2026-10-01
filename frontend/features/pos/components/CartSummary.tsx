"use client";

import type { ReactNode } from "react";
import { ShoppingCart } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { useCommittedNumber } from "@/features/pos/hooks/useCommittedNumber";
import {
  selectGlobalDiscount,
  selectTaxRate,
  useCartStore,
  useCartTotals,
} from "@/stores/useCartStore";

interface CartSummaryProps {
  /** Opens the payment modal. */
  onCheckout: () => void;
}

function SummaryRow({
  label,
  value,
  className,
}: {
  label: ReactNode;
  value: string;
  className?: string;
}) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className={className ?? "font-medium tabular-nums"}>{value}</span>
    </div>
  );
}

/**
 * Totals block: subtotal, discounts, VAT and grand total, plus the checkout
 * button that opens the payment modal.
 *
 * Every number comes from `useCartTotals()` — nothing is stored or
 * recalculated here, so the screen can never disagree with the Phase 3 store.
 * The global discount input commits through `setGlobalDiscount`, which clamps
 * the amount; the field then displays exactly what was stored (the hook snaps
 * it back), so "typed" and "applied" can never diverge.
 *
 * The checkout button carries the amount, so the cashier confirms the total in
 * the same glance and click — no extra step before the payment modal.
 */
export function CartSummary({ onCheckout }: CartSummaryProps) {
  const totals = useCartTotals();
  const globalDiscount = useCartStore(selectGlobalDiscount);
  const taxRate = useCartStore(selectTaxRate);

  const discountField = useCommittedNumber(globalDiscount, (next) =>
    useCartStore.getState().setGlobalDiscount(next),
  );

  const commitOnEnter =
    (commit: () => void) =>
    (event: React.KeyboardEvent<HTMLInputElement>) => {
      if (event.key === "Enter") {
        event.preventDefault();
        commit();
      }
    };

  return (
    <div className="shrink-0 space-y-2 border-t border-border pt-3">
      <SummaryRow label="Subtotal" value={formatMoney(totals.subtotal)} />

      {totals.itemDiscountTotal > 0 && (
        <SummaryRow
          label="Item discount"
          value={`−${formatMoney(totals.itemDiscountTotal)}`}
          className="font-medium tabular-nums text-warning"
        />
      )}

      <div className="flex items-center justify-between gap-2">
        <Label htmlFor="global-discount" className="text-sm text-muted-foreground">
          Global discount
        </Label>
        <Input
          id="global-discount"
          type="number"
          min={0}
          step={0.01}
          inputMode="decimal"
          placeholder="0.00"
          className="h-7 w-24 text-right text-sm tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
          value={discountField.text}
          onChange={(event) => discountField.setText(event.target.value)}
          onBlur={discountField.commit}
          onKeyDown={commitOnEnter(discountField.commit)}
        />
      </div>

      <SummaryRow
        label={
          <span>
            Tax / VAT ({formatQuantity(taxRate)}%)
          </span>
        }
        value={`+${formatMoney(totals.taxTotal)}`}
      />

      <Separator />

      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-semibold">Grand total</span>
        <span className="font-heading text-xl font-bold tabular-nums">
          {formatMoney(totals.grandTotal)}
        </span>
      </div>

      <Button
        type="button"
        size="lg"
        className="w-full"
        onClick={onCheckout}
        disabled={totals.lineCount === 0}
      >
        <ShoppingCart aria-hidden="true" />
        Checkout · {formatMoney(totals.grandTotal)}
      </Button>
    </div>
  );
}
