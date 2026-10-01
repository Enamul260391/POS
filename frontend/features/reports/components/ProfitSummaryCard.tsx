"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type { ProfitLossReport, SalesReport } from "@/types/report";

interface ProfitSummaryCardProps {
  /** The `GET /reports/profit-loss` response, already mapped. */
  profitLoss: ProfitLossReport;
  /** The `GET /reports/sales` response, for the revenue line. */
  sales: SalesReport;
}

/**
 * Report: profit summary — `GET /reports/profit-loss`.
 *
 * This card is the one place the API's *absence* of an answer is the content.
 * `ReportService::profitLoss()` returns `available: false` with a `limitation`
 * string, because `sale_items` does not store `unit_cost`: the cost of the
 * units on a historical invoice line is unrecoverable, so gross profit and net
 * profit cannot be produced without either inventing a cost or substituting
 * today's product cost — which would restate history using current prices.
 *
 * So the card shows revenue (a figure the sales report does send), renders
 * every cost-derived figure as "—", and prints the API's own explanation
 * verbatim. Nothing here is computed as a profit: a client-side
 * "revenue minus expenses" is deliberately **not** shown, because the API
 * reports profit as unavailable and the screen must not present a substitute in
 * its place.
 */
export const ProfitSummaryCard = memo(function ProfitSummaryCard({
  profitLoss,
  sales,
}: ProfitSummaryCardProps) {
  const netSales = sales.grossSales;

  return (
    <ReportCard
      title="Profit summary"
      description={
        netSales > 0
          ? `${formatMoney(netSales)} revenue in this period`
          : "No sales in this period"
      }
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
        <ReportMetric label="Revenue" value={formatMoney(netSales)} />
        <ReportMetric
          label="Cost of goods"
          value="—"
          hint={profitLoss.available ? undefined : "Cost unavailable"}
        />
        <ReportMetric
          label="Gross profit"
          value="—"
          hint={profitLoss.available ? undefined : "Cost unavailable"}
        />
        <ReportMetric
          label="Net profit"
          value="—"
          hint={profitLoss.available ? undefined : "Cost unavailable"}
        />
      </div>
      <p className="text-xs text-muted-foreground">
        {profitLoss.limitation ??
          "Historical cost of goods sold is not available for this period."}
      </p>
    </ReportCard>
  );
});

