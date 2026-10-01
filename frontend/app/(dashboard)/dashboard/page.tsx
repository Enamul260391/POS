import type { Metadata } from "next";

import { DashboardScreen } from "@/features/dashboard/components/DashboardScreen";

export const metadata: Metadata = {
  title: "Dashboard",
  description: "Today at a glance: sales, purchases, expenses and cash on hand.",
};

/**
 * `/dashboard` — the "Today at a glance" home screen.
 *
 * A thin route shell, matching every other area of the shell: the page is a
 * Server Component that owns `metadata`, while all loading / error / retry
 * state lives in `DashboardScreen`. The sidebar link for
 * "Dashboard → `/dashboard`" resolves here.
 *
 * The figures come from the Laravel dashboard endpoint
 * (`GET /api/v1/reports/dashboard`), which is scoped to the signed-in user's own
 * business by the session token — the page sends no business identifier of its
 * own. The endpoint is today-only by construction, so this screen offers no
 * date range and describes itself as today. A reload re-reads the database, so
 * the screen always reflects the server rather than cached or mock values.
 */
export default function DashboardPage() {
  return (
    <>
      <h1 className="sr-only">Dashboard</h1>
      <DashboardScreen />
    </>
  );
}
