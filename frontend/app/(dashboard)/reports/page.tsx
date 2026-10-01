import type { Metadata } from "next";

import { ReportsScreen } from "@/features/reports/components/ReportsScreen";

export const metadata: Metadata = {
  title: "Reports",
  description:
    "Sales, purchases, expenses and profit summaries with date filters and trend charts.",
};

/**
 * `/reports` — read-only reporting dashboard (Phase 12, mock).
 *
 * A thin route shell: the page is a Server Component that owns `metadata`, while
 * all interactive state (the date filter + the memoized report sheet) lives in
 * `ReportsScreen`. The sidebar link for "Reports → `/reports`" resolves here.
 */
export default function ReportsPage() {
  return (
    <>
      <h1 className="sr-only">Reports</h1>
      <ReportsScreen />
    </>
  );
}
