"use client";

import { Minus, Plus, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  canAddProduct,
  cartQuantityOf,
  remainingStock,
} from "@/features/pos/lib/catalog";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { useCommittedNumber } from "@/features/pos/hooks/useCommittedNumber";
import {
  quantityStep,
  useCartStore,
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
  "h-7 w-20 rounded-md border-input text-right text-sm tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none";

/**
 * One cart line: quantity controls, item discount and the line total.
 *
 * Reads master data (stock) from the API-backed catalogue rather than storing it
 * on the line — product data and cart state stay separate, per the
 * architecture. Quantity and discount commit on blur/Enter: free typing while
 * editing, then the field snaps back to whatever the store accepted (validated,
 * clamped), so the screen can never show a value the store rejected.
 *
 * The increase button disables at the stock limit — one of the three stock
 * guards (card, add helpers, this button) that all share `canAddProduct`.
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
  const canIncrease =
    product === undefined ? true : canAddProduct(product, inCartQuantity, step);

  // Store setters return what they actually stored — commit writes that back.
  const quantityField = useCommittedNumber(line.quantity, (next) => {
    const result = useCartStore.getState().setQuantity(line.id, next);
    if (!result.ok) {
      return line.quantity;
    }
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
    <li className="space-y-2 p-2.5">
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium leading-tight">
            {line.product.name}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {formatMoney(line.unitPrice)} / {line.product.unit}
            {remaining !== null && ` · ${formatQuantity(remaining)} left`}
          </p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={() => useCartStore.getState().removeItem(line.id)}
          aria-label={`Remove ${line.product.name}`}
        >
          <X aria-hidden="true" />
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-1.5">
        <div className="flex items-center rounded-lg border border-border bg-background">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => useCartStore.getState().decreaseQuantity(line.id, step)}
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
            disabled={!canIncrease}
            onClick={() => useCartStore.getState().increaseQuantity(line.id, step)}
            aria-label={`Increase quantity of ${line.product.name}`}
          >
            <Plus aria-hidden="true" />
          </Button>
        </div>

        <div className="flex min-w-0 flex-1 items-center gap-1.5">
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
      </div>

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
