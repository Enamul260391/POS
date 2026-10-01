"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportUnavailable } from "@/features/reports/components/ReportUnavailable";
import { REPORT_UNAVAILABLE_REASONS } from "@/types/report";

/**
 * Report: daily sales — **no backend report source**.
 *
 * The card is kept mounted, but `GET /reports/sales` answers with a single
 * aggregate row for the whole period: `ReportService::sales()` has no
 * `groupBy(sale_date)`, so there is no per-day series to plot and no other route
 * in `routes/api.php` produces one.
 *
 * It is deliberately left empty rather than reconstructed by fetching every
 * page of `/sales` and bucketing the invoices in the browser: that list is
 * paginated, so the resulting "daily sales" would silently cover only the first
 * few hundred invoices of the period while looking authoritative. Stating the
 * gap is the honest answer, and the trend returns as soon as the sales report
 * can group by date.
 */
export const DailySalesCard = memo(function DailySalesCard() {
  return (
    <ReportCard
      title="Daily sales"
      description="Sales trend by day"
      className="md:col-span-2"
    >
      <ReportUnavailable
        reason={REPORT_UNAVAILABLE_REASONS.dailySales}
        hint="A per-day series would need the sales report to group invoices by sale_date."
      />
    </ReportCard>
  );
});

