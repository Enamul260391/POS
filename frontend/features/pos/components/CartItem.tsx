"use client";

import { useState } from "react";
import { Minus, Plus, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  canAddProduct,
  cartQuantityOf,
  describeCartFailure,
  remainingStock,
} from "@/features/pos/lib/catalog";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { useCommittedNumber } from "@/features/pos/hooks/useCommittedNumber";
import {
  quantityStep,
  useCartStore,
  type CartActionResult,
  type CartLineTotals,
} from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { CartItem as CartLine } from "@/types/cart";

interface CartItemProps {
  line: CartLine;
  /** Calculated totals of this line, supplied by `CartPanel`. */
  totals: CartLineTotals;
}

/** Inline numeric fields: no spinners, fixed height. */
const QUANTITY_FIELD_CLASS =
  "h-7 w-12 border-0 bg-transparent px-1 text-center text-sm tabular-nums [appearance:textfield] focus-visible:border-0 focus-visible:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none";
const DISCOUNT_FIELD_CLASS =
  "h-7 w-20 rounded-md border-2 border-black/30 bg-background text-right text-sm tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none";

/**
 * Shown when the product's total ledger stock is exhausted.
 *
 * Distinct from the selling price group's shortfall: there the product still
 * has stock at another price, here nothing more can be sold at all.
 */
const STOCK_OUT_MESSAGE = "Stock Out";

/**
 * One cart line: quantity controls, item discount and the line total.
 *
 * Reads master data (stock) from the API-backed catalogue rather than storing it
 * on the line — product data and cart state stay separate, per the
 * architecture. Quantity and discount commit on blur/Enter: free typing while
 * editing, then the field snaps back to whatever the store accepted (validated,
 * clamped), so the screen can never show a value the store rejected.
 *
 * Stock guards
 * ------------
 * Two different limits can stop an increase, and they are told apart:
 *
 * - The product's **total** ledger stock, across every price group. This is
 *   the hard guard that shares `canAddProduct` with the card and the add
 *   helpers. When it is exhausted the cashier is told `Stock Out`: nothing
 *   more of this product can be sold at any price.
 * - The line's **own selling price group**, which may run out while the
 *   product still has stock elsewhere. The store refuses that change and
 *   names the reason, so the line shows
 *   `please add another batch of this product` and the cashier adds another
 *   batch by picking another price explicitly.
 *
 * The increase button stays clickable in both cases: a dead button tells the
 * cashier nothing, and the limit is reported where the action happened. It
 * only disables for a product that is no longer sellable — the same rule the
 * product card follows.
 */
export function CartItem({ line, totals }: CartItemProps) {
  // Subscribing to a single catalogue row (a primitive selector) means a stock
  // change after a sale re-renders only the affected lines.
  const product = useCatalogueStore((state) =>
    state.products.find((candidate) => candidate.id === line.productId),
  );
  const inCartQuantity = useCartStore((state) =>
    cartQuantityOf(state.items, line.productId),
  );
  const step = quantityStep(line.product.unit);
  const remaining = product === undefined ? null : remainingStock(product, inCartQuantity);
  // A product that is no longer sellable cannot be increased at all.
  const productSellable = product === undefined ? true : product.status === "active";

  // The refusal of the last quantity change, shown inside this line. One
  // message per line, so a single failed action can never stack duplicates.
  const [error, setError] = useState<string | null>(null);

  /** Applies a quantity change and states a refusal inside the line. */
  const runQuantityAction = (action: () => CartActionResult): void => {
    const result = action();
    setError(result.ok ? null : describeCartFailure(result.reason));
  };

  /**
   * Increases the line by one step.
   *
   * The product's total ledger stock is checked here, before the store is
   * called: when it cannot serve another unit the line reports `Stock Out`
   * and the cart is left untouched. Everything else is the store's decision,
   * and its refusal reason names the limit that stopped it.
   */
  const handleIncrease = (): void => {
    if (!productSellable) {
      return;
    }
    if (product !== undefined && !canAddProduct(product, inCartQuantity, step)) {
      setError(STOCK_OUT_MESSAGE);
      return;
    }
    runQuantityAction(() => useCartStore.getState().increaseQuantity(line.id, step));
  };

  // Store setters return what they actually stored — commit writes that back.
  const quantityField = useCommittedNumber(line.quantity, (next) => {
    const result = useCartStore.getState().setQuantity(line.id, next);
    if (!result.ok) {
      // Refused: the stored quantity stays as it was and the reason is shown.
      setError(describeCartFailure(result.reason));
      return line.quantity;
    }
    setError(null);
    const current = useCartStore
      .getState()
      .items.find((item) => item.id === line.id);
    // The line is gone (quantity reached zero) — the row unmounts anyway.
    return current === undefined ? next : current.quantity;
  });
  const discountField = useCommittedNumber(line.discount, (next) =>
    useCartStore.getState().setItemDiscount(line.id, next),
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
    <li className="space-y-2 mb-0.75 bg-white p-2.5 shadow-sm ml-2 mr-2">
      <div className="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium leading-tight">
            {line.product.name}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {formatMoney(line.unitPrice)} / {line.product.unit}
            {remaining !== null && ` · ${formatQuantity(remaining)} left`}
          </p>
        </div>

        <div className="flex items-center rounded-lg border border-border bg-background">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() =>
              runQuantityAction(() =>
                useCartStore.getState().decreaseQuantity(line.id, step),
              )
            }
            aria-label={`Decrease quantity of ${line.product.name}`}
          >
            <Minus aria-hidden="true" />
          </Button>
          <Input
            type="number"
            min={0}
            step={step}
            inputMode="decimal"
            className={QUANTITY_FIELD_CLASS}
            aria-label={`Quantity of ${line.product.name}`}
            value={quantityField.text}
            onChange={(event) => quantityField.setText(event.target.value)}
            onBlur={quantityField.commit}
            onKeyDown={commitOnEnter(quantityField.commit)}
          />
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled={!productSellable}
            onClick={handleIncrease}
            aria-label={`Increase quantity of ${line.product.name}`}
          >
            <Plus aria-hidden="true" />
          </Button>
        </div>

        <div className="flex items-center gap-1.5">
          <label
            htmlFor={`item-discount-${line.id}`}
            className="shrink-0 text-xs text-muted-foreground"
          >
            Disc
          </label>
          <Input
            id={`item-discount-${line.id}`}
            type="number"
            min={0}
            step={0.01}
            inputMode="decimal"
            placeholder="0"
            className={DISCOUNT_FIELD_CLASS}
            value={discountField.text}
            onChange={(event) => discountField.setText(event.target.value)}
            onBlur={discountField.commit}
            onKeyDown={commitOnEnter(discountField.commit)}
          />
        </div>

        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={() => useCartStore.getState().removeItem(line.id)}
          aria-label={`Remove ${line.product.name}`}
          className="col-start-4"
        >
          <X aria-hidden="true" />
        </Button>
      </div>

      {error !== null && (
        <p role="alert" className="text-xs text-destructive">
          {error}
        </p>
      )}

      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="text-muted-foreground">
          {formatMoney(line.unitPrice)} × {formatQuantity(line.quantity)}
          {line.discount > 0 && ` · disc −${formatMoney(line.discount)}`}
        </span>
        <span className="font-heading text-sm font-semibold tabular-nums">
          {formatMoney(totals.lineTotal)}
        </span>
      </div>
    </li>
  );
}
