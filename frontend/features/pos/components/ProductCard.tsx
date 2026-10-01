"use client";

import { Badge } from "@/components/ui/badge";
import {
  canAddProduct,
  cartQuantityOf,
  remainingStock,
  stockStatus,
  type StockStatus,
} from "@/features/pos/lib/catalog";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { useCartStore } from "@/stores/useCartStore";
import type { Product } from "@/types/product";

interface ProductCardProps {
  product: Product;
  /** Adds the product; the stock/active guard runs inside `addProductToSale`. */
  onAdd: (product: Product) => void;
}

/**
 * Stock indicator. One place decides the wording and colour so every card
 * (and later the receipt preview) reads stock the same way.
 *
 * - `service`  — not stock tracked (services), no numbers shown
 * - `out`      — nothing left: card disabled, nothing can be added
 * - `low`      — at or below the low-stock threshold: warning colour
 * - `in`       — normal: shows the units still sellable
 */
function StockBadge({
  product,
  remaining,
  status,
}: {
  product: Product;
  remaining: number | null;
  status: StockStatus;
}) {
  switch (status) {
    case "service":
      return (
        <Badge variant="outline" className="shrink-0 text-muted-foreground">
          Service
        </Badge>
      );
    case "out":
      return (
        <Badge variant="destructive" className="shrink-0">
          Out of stock
        </Badge>
      );
    case "low":
      return (
        <Badge
          variant="outline"
          className="shrink-0 border-warning/50 bg-warning/10 text-warning"
        >
          Low: {formatQuantity(remaining ?? 0)}
        </Badge>
      );
    case "in":
      return (
        <Badge variant="secondary" className="shrink-0">
          {formatQuantity(remaining ?? product.stock)} left
        </Badge>
      );
  }
}

/**
 * One tappable product.
 *
 * The whole card is a single button — minimum clicks for the cashier: tap and
 * the product is in the cart. It subscribes only to its own cart quantity
 * (a primitive selector result), so a cart change re-renders just the cards
 * whose quantity actually moved, never the grid or the page.
 *
 * Out-of-stock cards render disabled, which is the first of the three guards
 * (card, search Enter, barcode scan) that all share `canAddProduct`.
 */
export function ProductCard({ product, onAdd }: ProductCardProps) {
  const inCartQuantity = useCartStore((state) =>
    cartQuantityOf(state.items, product.id),
  );
  const remaining = remainingStock(product, inCartQuantity);
  const status = stockStatus(product, inCartQuantity);
  const canAdd = canAddProduct(product, inCartQuantity, 1);

  return (
    <li className="h-full min-w-0">
      <button
        type="button"
        onClick={() => onAdd(product)}
        disabled={!canAdd}
        className="flex h-full w-full flex-col gap-1.5 rounded-xl border border-border bg-card p-3 text-left outline-none transition-colors hover:border-foreground/30 hover:bg-muted/40 focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-border disabled:hover:bg-card"
      >
        <span className="flex w-full items-start justify-between gap-2">
          <span className="font-heading text-base font-semibold">
            {formatMoney(product.sellingPrice)}
          </span>
          <StockBadge product={product} remaining={remaining} status={status} />
        </span>

        <span className="line-clamp-2 min-h-9 text-sm font-medium leading-tight">
          {product.name}
        </span>

        <span className="mt-auto flex w-full items-center justify-between gap-2 text-xs text-muted-foreground">
          <span className="truncate font-mono">
            {product.sku} · {product.unit}
          </span>
          {inCartQuantity > 0 && (
            <span className="shrink-0 font-medium text-primary">
              in cart: {formatQuantity(inCartQuantity)}
            </span>
          )}
        </span>
      </button>
    </li>
  );
}
