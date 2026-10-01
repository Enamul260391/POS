"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportUnavailable } from "@/features/reports/components/ReportUnavailable";
import { REPORT_UNAVAILABLE_REASONS } from "@/types/report";

/**
 * Report: monthly sales — **no backend report source**.
 *
 * Same gap as the daily report, at month granularity: `ReportService::sales()`
 * returns one aggregate row for the whole window and never groups by month, and
 * no other route in `routes/api.php` provides a monthly series.
 *
 * It is intentionally not rebuilt by paging through `/sales` and bucketing the
 * invoices client-side — the list is paginated, so a partial sum would be
 * presented as the month total. The card states the gap instead.
 */
export const MonthlySalesCard = memo(function MonthlySalesCard() {
  return (
    <ReportCard title="Monthly sales" description="Sales by calendar month">
      <ReportUnavailable
        reason={REPORT_UNAVAILABLE_REASONS.monthlySales}
        hint="A per-month series would need the sales report to group invoices by month."
      />
    </ReportCard>
  );
});

