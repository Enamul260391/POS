import type { Metadata } from "next";

import { PlatformBusinessList } from "@/features/platform/components/PlatformBusinessList";

export const metadata: Metadata = {
  title: "Businesses",
  description: "Manage all businesses on the platform",
};

/**
 * `/platform/businesses` — Platform business management.
 */
export default function PlatformBusinessesPage() {
  return (
    <>
      <h1 className="sr-only">Businesses</h1>
      <PlatformBusinessList />
    </>
  );
}