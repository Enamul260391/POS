"use client";

import { useDeferredValue, useEffect, useMemo, useRef, useState } from "react";
import { Loader2, Plus, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  ProductFormDialog,
  type ProductFormTarget,
} from "@/features/products/components/ProductFormDialog";
import { ProductFilters } from "@/features/products/components/ProductFilters";
import { ProductTable } from "@/features/products/components/ProductTable";
import { ProductViewDialog } from "@/features/products/components/ProductViewDialog";
import { filterProducts } from "@/features/products/lib/products";
import {
  selectCategories,
  selectProducts,
  useCatalogueStore,
} from "@/stores/useProductStore";
import type { Product, ProductStatus } from "@/types/product";

/**
 * `/products` screen — composition only (Phase 7, Phase 25).
 *
 * Owns the local filter state and which dialog (if any) is mounted. The
 * catalogue itself comes from `useCatalogueStore`, which hydrates from
 * `GET /products` and `GET /categories`; `mock/products.json` is no longer
 * referenced.
 *
 * Phase 25 kept the interaction exactly as it was — client-side filtering over
 * the loaded catalogue, the same filter row, the same dialogs — and added only
 * what an API-backed screen needs: a first-load state, an error state with a
 * retry, and a submit-failure notice on the forms.
 */
export function ProductsScreen() {
  const products = useCatalogueStore(selectProducts);
  const categories = useCatalogueStore(selectCategories);
  const isLoading = useCatalogueStore((state) => state.isLoading);
  const isLoaded = useCatalogueStore((state) => state.isLoaded);
  const error = useCatalogueStore((state) => state.error);
  const categoryError = useCatalogueStore((state) => state.categoryError);
  const hydrate = useCatalogueStore((state) => state.hydrate);

  const [query, setQuery] = useState("");
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [status, setStatus] = useState<"all" | ProductStatus>("all");
  const [formTarget, setFormTarget] = useState<ProductFormTarget | null>(null);
  const [viewTarget, setViewTarget] = useState<Product | null>(null);

  // One automatic load per mount, and only one.
  //
  // The `!isLoaded && !isLoading` test alone is not enough: a **failed** load
  // leaves `isLoaded` false and `isLoading` false again, so the effect would
  // re-fire, retry, fail, and re-fire — an endless request loop behind a spinner
  // that never resolves. This ref records that the automatic load already
  // happened, leaving every later load to an explicit Refresh / Try again click.
  const hasAutoLoaded = useRef(false);

  useEffect(() => {
    if (hasAutoLoaded.current) {
      return;
    }
    hasAutoLoaded.current = true;
    void hydrate();
  }, [hydrate]);

  const deferredQuery = useDeferredValue(query);
  const visibleProducts = useMemo(
    () => filterProducts(products, { query: deferredQuery, categoryId, status }),
    [products, deferredQuery, categoryId, status],
  );

  return (
    <section aria-label="Products" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            void hydrate();
          }}
          disabled={isLoading}
        >
          {isLoading ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>
        <Button
          type="button"
          onClick={() => setFormTarget({ mode: "add" })}
          disabled={!isLoaded}
        >
          <Plus aria-hidden="true" />
          Add product
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => {
              void hydrate();
            }}
          >
            Try again
          </Button>
        </div>
      )}

      {/* A category failure is reported on its own, as a warning: the product
          table below is still valid, so this must not look like a failed load.
          It stays actionable because `hydrate` re-requests both lists. */}
      {categoryError !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
        >
          <span>
            {categoryError} Products are shown, but the category filter and the
            category list are unavailable.
          </span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => {
              void hydrate();
            }}
          >
            Try again
          </Button>
        </div>
      )}

      <ProductFilters
        query={query}
        onQueryChange={setQuery}
        categories={categories}
        categoryId={categoryId}
        onCategoryChange={setCategoryId}
        status={status}
        onStatusChange={setStatus}
        shownCount={visibleProducts.length}
        totalCount={products.length}
        isLoading={isLoading && !isLoaded}
      />

      <ProductTable
        products={visibleProducts}
        categories={categories}
        onView={setViewTarget}
        onEdit={(product) => setFormTarget({ mode: "edit", product })}
      />

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {formTarget !== null && (
        <ProductFormDialog
          target={formTarget}
          onOpenChange={(open) => {
            if (!open) {
              setFormTarget(null);
            }
          }}
        />
      )}
      {viewTarget !== null && (
        <ProductViewDialog
          product={viewTarget}
          categories={categories}
          onOpenChange={(open) => {
            if (!open) {
              setViewTarget(null);
            }
          }}
        />
      )}
    </section>
  );
}
