"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { ReportUnavailable } from "@/features/reports/components/ReportUnavailable";
import { formatQuantity } from "@/features/pos/lib/format";
import { REPORT_UNAVAILABLE_REASONS } from "@/types/report";
import type { InventoryReport } from "@/types/report";

interface LowStockCardProps {
  /** The `GET /reports/inventory` response, already mapped. */
  inventory: InventoryReport;
}

/**
 * Report: low stock — `GET /reports/inventory`.
 *
 * Deliberately NOT date-filtered: the endpoint is a current snapshot derived
 * from the ledger, so these numbers describe right now regardless of the
 * selected window, and the controller validates the period and ignores it.
 *
 * The metrics are the API's own counts (`product_count`, `total_stock`,
 * `low_stock_count`) plus the ledger's in/out totals it also reports. The
 * per-product table this card used to show is **not** rendered: the inventory
 * report counts the products at or below their threshold but does not say
 * *which* ones, so the panel below states that instead of a list built from the
 * paginated `/products` endpoint.
 */
export const LowStockCard = memo(function LowStockCard({
  inventory,
}: LowStockCardProps) {
  const lowCount = inventory.lowStockCount;

  return (
    <ReportCard
      title="Low stock"
      description={
        lowCount > 0
          ? `${lowCount} of ${inventory.productCount} products need attention`
          : "All products are above their low-stock threshold"
      }
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric label="To replenish" value={lowCount} />
        <ReportMetric
          label="Products tracked"
          value={inventory.productCount}
        />
        <ReportMetric
          label="Units on hand"
          value={formatQuantity(inventory.totalStock)}
        />
        <ReportMetric
          label="Ledger movements"
          value={inventory.stockMovementCount}
          hint={`${formatQuantity(inventory.totalStockIn)} in · ${formatQuantity(inventory.totalStockOut)} out`}
        />
      </div>

      <ReportUnavailable
        reason={REPORT_UNAVAILABLE_REASONS.lowStockItems}
        hint="The item list would need the inventory report to return the products behind the count."
      />
    </ReportCard>
  );
});


