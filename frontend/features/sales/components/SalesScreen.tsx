"use client";

import { useEffect, useMemo, useState } from "react";
import { Loader2, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { SaleReturnDialog } from "@/features/sales/components/SaleReturnDialog";
import { SalesFilters } from "@/features/sales/components/SalesFilters";
import { SalesTable } from "@/features/sales/components/SalesTable";
import { refundTotalForSale, returnableQuantities } from "@/features/sales/lib/returns";
import {
  lastSaleFilters,
  selectSalePagination,
  selectSaleReturns,
  selectSales,
  useSalesStore,
} from "@/stores/useSalesStore";
import type { Sale, SalePaymentStatus } from "@/types/sale";

/**
 * `/sales` screen — composition only (Phase 10, Phase 25).
 *
 * The invoice page and every recorded return come from the API through
 * `useSalesStore`: `GET /sales` (filtered, paginated and ordered by the server)
 * plus `GET /sale-returns`, which is read once because the table nets off the
 * refunded amount and the returnable quantity per invoice.
 *
 * Search and the payment-status filter are sent to the backend instead of being
 * applied to a downloaded array. The term is debounced so typing does not fire a
 * request per keystroke, and the status filter is translated from the UI's
 * `unpaid` to the API's `pending`.
 *
 * The two derived collections the table needs are built in ONE pass over the
 * invoices, from the shared pure helpers in `lib/returns`, so the table cannot
 * disagree with the return dialog.
 */
export function SalesScreen() {
  const sales = useSalesStore(selectSales);
  const saleReturns = useSalesStore(selectSaleReturns);
  const pagination = useSalesStore(selectSalePagination);
  const isLoading = useSalesStore((state) => state.isLoading);
  const isLoaded = useSalesStore((state) => state.isLoaded);
  const error = useSalesStore((state) => state.error);
  const load = useSalesStore((state) => state.load);
  const loadReturns = useSalesStore((state) => state.loadReturns);

  const [query, setQuery] = useState("");
  const [paymentStatus, setPaymentStatus] = useState<"all" | SalePaymentStatus>("all");
  const [returnTarget, setReturnTarget] = useState<Sale | null>(null);

  // One request per filter change, debounced while the cashier types.
  useEffect(() => {
    const handle = setTimeout(() => {
      void load(
        {
          search: query,
          // The UI calls the API's `pending` "unpaid".
          paymentStatus:
            paymentStatus === "all"
              ? undefined
              : paymentStatus === "unpaid"
                ? "pending"
                : paymentStatus,
        },
        1,
      );
    }, 300);
    return () => clearTimeout(handle);
  }, [load, query, paymentStatus]);

  // Returns are needed for the refunded badge and the returnable flag; they are
  // fetched once (and again on a manual refresh), never per row.
  const returnsLoaded = useSalesStore((state) => state.returnsLoaded);
  useEffect(() => {
    if (!returnsLoaded) {
      void loadReturns();
    }
  }, [returnsLoaded, loadReturns]);

  const { refundedBySale, returnableSaleIds } = useMemo(() => {
    const refunded = new Map<number, number>();
    const returnableIds = new Set<number>();

    for (const sale of sales) {
      const refundTotal = refundTotalForSale(saleReturns, sale.id);
      if (refundTotal > 0) {
        refunded.set(sale.id, refundTotal);
      }
      // A voided invoice has nothing to take back.
      if (sale.status === "void") {
        continue;
      }
      for (const remaining of returnableQuantities(sale, saleReturns).values()) {
        if (remaining > 0) {
          returnableIds.add(sale.id);
          break;
        }
      }
    }

    return { refundedBySale: refunded, returnableSaleIds: returnableIds };
  }, [sales, saleReturns]);

  const refresh = () => {
    void load(lastSaleFilters() ?? { search: query }, pagination?.current_page ?? 1);
    void loadReturns();
  };

  return (
    <section aria-label="Sales" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end">
        <Button type="button" variant="outline" onClick={refresh} disabled={isLoading}>
          {isLoading ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button type="button" size="sm" variant="outline" onClick={refresh}>
            Try again
          </Button>
        </div>
      )}

      <SalesFilters
        query={query}
        onQueryChange={setQuery}
        paymentStatus={paymentStatus}
        onPaymentStatusChange={setPaymentStatus}
        shownCount={sales.length}
        totalCount={pagination?.total ?? sales.length}
      />

      <SalesTable
        sales={sales}
        refundedBySale={refundedBySale}
        returnableSaleIds={returnableSaleIds}
        onReturn={setReturnTarget}
        isLoading={isLoading && !isLoaded}
      />

      {/* Mounted only while open: every return starts from fresh state. */}
      {returnTarget !== null && (
        <SaleReturnDialog
          sale={returnTarget}
          onOpenChange={(open) => {
            if (!open) {
              setReturnTarget(null);
            }
          }}
        />
      )}
    </section>
  );
}

