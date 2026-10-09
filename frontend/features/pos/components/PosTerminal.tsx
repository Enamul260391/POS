"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { CartPanel } from "@/features/pos/components/CartPanel";
import { PaymentModal } from "@/features/pos/components/PaymentModal";
import { ProductGrid } from "@/features/pos/components/ProductGrid";
import { ProductSearch } from "@/features/pos/components/ProductSearch";
import { usePosProductSearch } from "@/features/pos/hooks/usePosProductSearch";
import { addProductToSale, sellableProductsOf } from "@/features/pos/lib/catalog";
import type { Product } from "@/types/product";

/**
 * The POS terminal — composition only (Phase 3, Phase 25).
 *
 * Owns the search state (query) and the payment modal flag; the cart itself
 * lives in the Phase 3 store and totals are derived from it. Deliberate
 * re-render boundaries:
 *
 * - This component never subscribes to the cart (handlers read
 *   `useCartStore.getState()` inside the helpers), so cart updates do not
 *   re-render the search box or grid.
 * - `ProductGrid` is memoised and holds no subscription; each `ProductCard`
 *   subscribes to its own quantity and updates alone.
 * - `CartPanel` subscribes to the cart only, so typing in the search box does
 *   not re-render the cart.
 *
 * Keyboard workflow: `F2` barcode, `F3` search, `Enter` adds, `Esc` clears —
 * suppressed while the payment dialog owns focus.
 *
 * Catalogue loading (Phase 25 follow-up)
 * -------------------------------------
 * The terminal **never loads the product catalogue**. There is no `hydrate`
 * call and no background prefetch: on mount it issues zero product requests,
 * and the result area stays empty until the cashier searches. Products come
 * from `GET /products?search=…&status=active` through `usePosProductSearch`, so
 * the backend performs the match and only the matching rows are ever held.
 *
 * `sellableProductsOf` is kept as a defence in depth even though the server
 * already filters `status=active` — the POS must never sell an inactive row
 * even if that contract ever changed.
 *
 * No category tabs, ever. The result area deliberately has no category filter:
 * the terminal holds no catalogue of its own, so a tab bar could only ever be
 * filled from categories cached by another screen (`/products`, `/inventory`
 * and `/purchases` hydrate the shared catalogue store) — which made the tabs
 * pop in and out depending on navigation history. The cashier narrows results
 * through the search box and the barcode field, both of which ask the backend
 * to match.
 */
export function PosTerminal() {
  const [query, setQuery] = useState("");
  const [isPaymentOpen, setIsPaymentOpen] = useState(false);

  // Server-side search. Empty query → no request and no products.
  const { products, isSearching, error, term } = usePosProductSearch(query);

  const searchRef = useRef<HTMLInputElement>(null);
  const barcodeRef = useRef<HTMLInputElement>(null);

  const sellableProducts = useMemo(
    () => sellableProductsOf(products),
    [products],
  );

  // Results are search-driven: an empty query renders no product result area at
  // all (no cards). There is no `filterByCategory` and no category tab state:
  // the search is a plain term sent to the backend, so no shared or stale
  // category selection can ever influence what the terminal shows.
  const hasQuery = term !== "";
  const firstResult = hasQuery ? (sellableProducts[0] ?? null) : null;

  const handleAdd = useCallback((product: Product) => {
    addProductToSale(product);
  }, []);

  useEffect(() => {
    if (isPaymentOpen) {
      return;
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "F2") {
        event.preventDefault();
        barcodeRef.current?.focus();
      } else if (event.key === "F3") {
        event.preventDefault();
        searchRef.current?.focus();
        searchRef.current?.select();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isPaymentOpen]);

  return (
    <div className="flex min-h-0 w-full flex-1 flex-col gap-3 lg:h-[calc(100dvh-5.5rem)] lg:flex-row lg:gap-0 lg:overflow-hidden">
      <section
        aria-label="Product catalog"
        className="flex min-h-0 flex-1 flex-col gap-2.5 lg:pr-4"
      >
        <ProductSearch
          query={query}
          onQueryChange={setQuery}
          firstResult={firstResult}
          searchRef={searchRef}
          barcodeRef={barcodeRef}
        />
        {error !== null && (
          <p role="alert" className="text-xs text-destructive">
            {error}
          </p>
        )}
        {hasQuery && (
          <ProductGrid
            products={sellableProducts}
            onAdd={handleAdd}
            isLoading={isSearching}
          />
        )}
      </section>

      <CartPanel onCheckout={() => setIsPaymentOpen(true)} />
      <PaymentModal open={isPaymentOpen} onOpenChange={setIsPaymentOpen} />
    </div>
  );
}
