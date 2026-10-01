"use client";

import { useDeferredValue, useEffect, useMemo, useState } from "react";
import { Loader2, Plus, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PurchaseDetails } from "@/features/purchases/components/PurchaseDetails";
import { PurchaseFilters } from "@/features/purchases/components/PurchaseFilters";
import { PurchaseFormDialog } from "@/features/purchases/components/PurchaseFormDialog";
import { PurchaseTable } from "@/features/purchases/components/PurchaseTable";
import { filterPurchases } from "@/features/purchases/lib/purchases";
import { useCatalogueStore } from "@/stores/useProductStore";
import { selectPurchases, lastPurchaseFilters, usePurchaseStore } from "@/stores/usePurchaseStore";
import { useSupplierStore } from "@/stores/useSupplierStore";
import type { Purchase, PurchasePaymentStatus } from "@/types/purchase";

/**
 * `/purchases` screen — composition only (Phase 9, Phase 25).
 *
 * Owns the local filter state and which dialog (if any) is mounted; the order
 * list lives in `usePurchaseStore`, hydrated from `GET /purchases`.
 *
 * Phase 25 kept the interaction and added only what an API-backed screen
 * needs: the search term and payment-status filter are sent to the backend
 * (debounced) instead of filtering a downloaded array, the supplier and
 * product masters the create dialog needs are loaded once, and the screen
 * gained a loading state plus an error state with a retry.
 *
 * Opening the details dialog fetches `GET /purchases/{id}` first, because the
 * list endpoint deliberately omits the lines (no N+1).
 */
export function PurchasesScreen() {
  const purchases = usePurchaseStore(selectPurchases);
  const pagination = usePurchaseStore((state) => state.pagination);
  const isLoading = usePurchaseStore((state) => state.isLoading);
  const isLoaded = usePurchaseStore((state) => state.isLoaded);
  const error = usePurchaseStore((state) => state.error);
  const load = usePurchaseStore((state) => state.load);
  const loadPurchase = usePurchaseStore((state) => state.loadPurchase);

  const suppliersLoaded = useSupplierStore((state) => state.isLoaded);
  const loadSuppliers = useSupplierStore((state) => state.load);
  const catalogueLoaded = useCatalogueStore((state) => state.isLoaded);
  const hydrateCatalogue = useCatalogueStore((state) => state.hydrate);

  const [query, setQuery] = useState("");
  const [paymentStatus, setPaymentStatus] = useState<
    "all" | PurchasePaymentStatus
  >("all");
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [detailsTarget, setDetailsTarget] = useState<Purchase | null>(null);

  // The create dialog selects suppliers and products, so both masters are read
  // once (guarded by their own `isLoaded` flags, never on every render).
  useEffect(() => {
    if (!suppliersLoaded) {
      void loadSuppliers({}, 1);
    }
  }, [suppliersLoaded, loadSuppliers]);

  useEffect(() => {
    if (!catalogueLoaded) {
      void hydrateCatalogue();
    }
  }, [catalogueLoaded, hydrateCatalogue]);

  // One request per filter change, debounced while the buyer types. The UI's
  // `unpaid` is the API's `pending`, so the filter is translated on the way
  // out; the client-side pass below is a cheap second check on the page shown.
  const loadWithFilters = () => {
    void load(
      {
        search: query,
        paymentStatus:
          paymentStatus === "all"
            ? undefined
            : paymentStatus === "unpaid"
              ? "pending"
              : paymentStatus,
      },
      1,
    );
  };

  useEffect(() => {
    const handle = setTimeout(loadWithFilters, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [load, query, paymentStatus]);

  const deferredQuery = useDeferredValue(query);
  const visiblePurchases = useMemo(
    () => filterPurchases(purchases, { query: deferredQuery, paymentStatus }),
    [purchases, deferredQuery, paymentStatus],
  );

  // The list rows carry no lines, so the full document is fetched first and
  // the dialog opens with it (falling back to the list row if the fetch fails —
  // the error banner reports the failure).
  const viewPurchase = async (purchase: Purchase) => {
    const detailed = await loadPurchase(purchase.id);
    setDetailsTarget(detailed ?? purchase);
  };

  const retry = () => {
    void load(lastPurchaseFilters(), pagination?.current_page ?? 1);
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-semibold tracking-tight">Purchases</h1>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label="Refresh purchases"
            onClick={retry}
            disabled={isLoading}
          >
            <RefreshCw className={isLoading ? "animate-spin" : undefined} aria-hidden="true" />
          </Button>
          <Button type="button" size="sm" onClick={() => setIsFormOpen(true)}>
            <Plus aria-hidden="true" />
            New purchase
          </Button>
        </div>
      </div>

      <PurchaseFilters
        query={query}
        onQueryChange={setQuery}
        paymentStatus={paymentStatus}
        onPaymentStatusChange={setPaymentStatus}
        shownCount={visiblePurchases.length}
        totalCount={pagination?.total ?? purchases.length}
      />

      {error !== null && (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
          <p role="alert" className="text-destructive">
            {error}
          </p>
          <Button type="button" variant="outline" size="sm" onClick={retry}>
            Retry
          </Button>
        </div>
      )}

      {isLoading && !isLoaded ? (
        <div
          className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-border px-6 py-12 text-sm text-muted-foreground"
          role="status"
          aria-live="polite"
        >
          <Loader2 className="size-4 animate-spin" aria-hidden="true" />
          Loading purchases…
        </div>
      ) : (
        <PurchaseTable purchases={visiblePurchases} onView={(purchase) => void viewPurchase(purchase)} />
      )}

      {isFormOpen && <PurchaseFormDialog onOpenChange={setIsFormOpen} />}

      {detailsTarget !== null && (
        <PurchaseDetails
          purchase={detailsTarget}
          onOpenChange={(open) => {
            if (!open) {
              setDetailsTarget(null);
            }
          }}
        />
      )}
    </div>
  );
}
