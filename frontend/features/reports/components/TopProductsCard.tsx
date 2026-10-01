"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportUnavailable } from "@/features/reports/components/ReportUnavailable";
import { REPORT_UNAVAILABLE_REASONS } from "@/types/report";

/**
 * Report: top selling products — **no backend report source**.
 *
 * `ReportService::sales()` aggregates the `sales` header only: it never joins
 * `sale_items` or `products`, so it cannot rank anything, and no other route in
 * `routes/api.php` returns a product leaderboard.
 *
 * It is intentionally not rebuilt from the `/products` catalogue or by paging
 * through `/sales` and summing the lines in the browser — the sales list is
 * paginated, so the "top 5" would really be the top 5 of whichever page loaded
 * first, presented as if it were the period's leaderboard. The card states the
 * gap instead, and fills in as soon as the sales report can group by product.
 */
export const TopProductsCard = memo(function TopProductsCard() {
  return (
    <ReportCard
      title="Top selling products"
      description="Best sellers by revenue"
      className="md:col-span-2"
    >
      <ReportUnavailable
        reason={REPORT_UNAVAILABLE_REASONS.topProducts}
        hint="A product ranking would need the sales report to group sale items by product."
      />
    </ReportCard>
  );
});

