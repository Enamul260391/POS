"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type { PurchaseReport } from "@/types/report";

interface PurchaseSummaryCardProps {
  /** The `GET /reports/purchases` response, already mapped. */
  purchases: PurchaseReport;
}

/**
 * Report: purchase summary — `GET /reports/purchases`.
 *
 * Headline spend for the window: how many orders came in, how much is owed and
 * how much has been settled. All four figures are the API's own aggregates —
 * `purchase_total` is the sum of the matched orders' grand totals, already net
 * of discount and inclusive of tax, so it is never re-derived here. `item
 * quantity` (units received) is the one extra metric the endpoint provides.
 */
export const PurchaseSummaryCard = memo(function PurchaseSummaryCard({
  purchases,
}: PurchaseSummaryCardProps) {
  const settled = purchases.paidAmount / purchases.grandTotal;
  const settledLabel =
    purchases.grandTotal > 0
      ? `${Math.round(settled * 100)}% paid`
      : "No orders";

  return (
    <ReportCard
      title="Purchase summary"
      description={
        purchases.orderCount > 0
          ? `${purchases.orderCount} orders · ${formatMoney(purchases.grandTotal)} total`
          : "No purchases in this period"
      }
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-2">
        <ReportMetric label="Orders" value={purchases.orderCount} />
        <ReportMetric
          label="Total spend"
          value={formatMoney(purchases.grandTotal)}
          hint={settledLabel}
        />
        <ReportMetric label="Paid" value={formatMoney(purchases.paidAmount)} />
        <ReportMetric
          label="Due"
          value={formatMoney(purchases.dueAmount)}
          hint={
            purchases.dueAmount > 0
              ? "Unpaid balance"
              : purchases.grandTotal > 0
                ? "Fully settled"
                : undefined
          }
        />
      </div>
    </ReportCard>
  );
});

