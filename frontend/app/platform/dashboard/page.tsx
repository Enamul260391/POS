import type { Metadata } from "next";

import { PlatformDashboard } from "@/features/platform/components/PlatformDashboard";

export const metadata: Metadata = {
  title: "Platform Dashboard",
  description: "Overview of all businesses on the platform",
};

/**
 * `/platform/dashboard` — Platform Owner dashboard.
 */
export default function PlatformDashboardPage() {
  return (
    <>
      <h1 className="sr-only">Platform Dashboard</h1>
      <PlatformDashboard />
    </>
  );
}