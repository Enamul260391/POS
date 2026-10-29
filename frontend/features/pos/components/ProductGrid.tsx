"use client";

import { memo } from "react";
import { Loader2, ShoppingBasket } from "lucide-react";

import { ProductCard } from "@/features/pos/components/ProductCard";
import type { Product } from "@/types/product";

interface ProductGridProps {
  /** Products matching the current search. */
  products: readonly Product[];
  /** Adds a tapped product to the cart (guard lives in the catalog helpers). */
  onAdd: (product: Product) => void;
  /** `true` while a product search request is in flight. */
  isLoading?: boolean;
}

/**
 * Grid of sellable products.
 *
 * Memoised on purpose: the grid itself never subscribes to the cart, so cart
 * updates cannot re-render it — each `ProductCard` subscribes to its own
 * quantity instead and updates independently. Re-renders happen only when the
 * filtered result array actually changes (search or a rare catalogue reload).
 */
export const ProductGrid = memo(function ProductGrid({
  products,
  onAdd,
  isLoading = false,
}: ProductGridProps) {
  if (isLoading) {
    return (
      <div
        className="flex min-h-0 flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center"
        role="status"
        aria-live="polite"
      >
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-1.5 px-6 py-10 text-center">
        <ShoppingBasket className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No products found</p>
        <p className="text-xs text-muted-foreground">
          Try a different name, SKU or barcode.
        </p>
      </div>
    );
  }

  return (
    <div className="min-h-0 flex-1 overflow-y-auto">
      <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} onAdd={onAdd} />
        ))}
      </ul>
    </div>
  );
});
