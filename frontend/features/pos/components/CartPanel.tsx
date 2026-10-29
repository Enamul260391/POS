"use client";

import { useMemo } from "react";
import { ShoppingBag, ShoppingBasket } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { CartItem } from "@/features/pos/components/CartItem";
import { CartSummary } from "@/features/pos/components/CartSummary";
import { CustomerSelector } from "@/features/pos/components/CustomerSelector";
import { useCartItems, useCartTotals } from "@/stores/useCartStore";

interface CartPanelProps {
  /** Opens the payment modal (passed through to `CartSummary`). */
  onCheckout: () => void;
}

/**
 * Right hand column: running sale.
 *
 * Subscribes only to the cart (items + derived totals), never to the
 * catalogue or the search state, so typing in the left column does not
 * re-render it — and its own updates never re-render the product grid
 * (which holds no store subscription at all).
 */
export function CartPanel({ onCheckout }: CartPanelProps) {
  const items = useCartItems();
  const totals = useCartTotals();

  const totalsByItemId = useMemo(
    () => new Map(totals.lines.map((lineTotals) => [lineTotals.itemId, lineTotals])),
    [totals],
  );

  return (
    <aside
      aria-label="Current sale"
      className="flex w-full shrink-0 flex-col border-t border-border bg-gradient-to-br from-violet-500/25 via-pink-500/15 to-amber-400/25 lg:h-full lg:min-h-0 lg:w-[570px] lg:border-t-0 lg:border-l xl:w-[610px]"
    >
      <div className="flex shrink-0 items-center gap-2 border-b border-border px-3 py-2.5">
        <ShoppingBag className="size-4" aria-hidden="true" />
        <h2 className="font-heading text-sm font-semibold">Current sale</h2>
        <Badge variant="secondary" className="ml-auto">
          {totals.lineCount} {totals.lineCount === 1 ? "line" : "lines"}
        </Badge>
      </div>

      {/* Customer selector (Phase 8): attaches a customer to this sale. */}
      <div className="shrink-0 border-b border-border px-3 py-2">
        <CustomerSelector />
      </div>

      {items.length === 0 ? (
        <div className="flex min-h-40 flex-1 flex-col items-center justify-center gap-1.5 px-6 text-center">
          <ShoppingBasket className="size-8 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium">Cart is empty</p>
          <p className="text-xs text-muted-foreground">
            Scan a barcode or tap a product to start the sale.
          </p>
        </div>
      ) : (
        <ul className="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
          {items.map((item) => {
            const lineTotals = totalsByItemId.get(item.id);
            if (lineTotals === undefined) {
              return null;
            }
            return <CartItem key={item.id} line={item} totals={lineTotals} />;
          })}
        </ul>
      )}

      <CartSummary onCheckout={onCheckout} />
    </aside>
  );
}
