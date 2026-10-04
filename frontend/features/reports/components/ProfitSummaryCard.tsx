"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type { ProfitLossReport } from "@/types/report";

interface ProfitSummaryCardProps {
  /** The `GET /reports/profit-loss` response, already mapped. */
  profitLoss: ProfitLossReport;
}

/**
 * Report: profit summary — `GET /reports/profit-loss`.
 *
 * Every figure is the API's own aggregate for the selected window. Cost of
 * goods is real, not estimated: `sale_items.unit_cost` snapshots the product's
 * `cost_price` at the moment of sale, so a historical line is costed at what
 * it actually cost rather than at today's price.
 *
 * The API withholds the cost-derived figures (`null`) whenever any line in the
 * period has no recorded unit cost, because summing an unknown cost as zero
 * would overstate the profit. This card renders a dash for those and prints the
 * APIs own explanation verbatim — it never substitutes a client-side
 * "revenue minus expenses", which is exactly the guess the backend declined to
 * make.
 */
export const ProfitSummaryCard = memo(function ProfitSummaryCard({
  profitLoss,
}: ProfitSummaryCardProps) {
  const revenue = profitLoss.revenue;
  // A withheld figure stays a dash; it is never coerced to 0.
  const dash = "—";
  const withheldHint = `${profitLoss.unpricedLines} unpriced line${profitLoss.unpricedLines === 1 ? "" : "s"}`;

  return (
    <ReportCard
      title="Profit summary"
      description={
        revenue > 0
          ? `${formatMoney(revenue)} revenue in this period`
          : "No sales in this period"
      }
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
        <ReportMetric
          label="Revenue"
          value={formatMoney(revenue)}
          hint={
            profitLoss.discount > 0 || profitLoss.tax > 0
              ? `-${formatMoney(profitLoss.discount)} off · ${formatMoney(profitLoss.tax)} tax`
              : undefined
          }
        />
        <ReportMetric
          label="Cost of goods"
          value={profitLoss.costOfGoods === null ? dash : formatMoney(profitLoss.costOfGoods)}
          hint={profitLoss.costOfGoods === null ? withheldHint : undefined}
        />
        <ReportMetric
          label="Gross profit"
          value={profitLoss.grossProfit === null ? dash : formatMoney(profitLoss.grossProfit)}
          hint={
            profitLoss.grossMarginPercent === null
              ? undefined
              : `${profitLoss.grossMarginPercent}% margin`
          }
        />
        <ReportMetric
          label="Expenses"
          value={formatMoney(profitLoss.totalExpense)}
        />
        <ReportMetric
          label="Net profit"
          value={profitLoss.netProfit === null ? dash : formatMoney(profitLoss.netProfit)}
          hint={profitLoss.netProfit === null ? withheldHint : undefined}
        />
      </div>
      {profitLoss.limitation && (
        <p className="text-xs text-muted-foreground">
          {profitLoss.limitation}
        </p>
      )}
    </ReportCard>
  );
});

