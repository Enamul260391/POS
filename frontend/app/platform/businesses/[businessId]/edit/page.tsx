import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { PlatformBusinessEditClient } from "@/features/platform/components/PlatformBusinessEditClient";
import { getBusiness } from "@/features/platform/services/platformBusinessService";

export const metadata: Metadata = {
  title: "Edit Business",
  description: "Edit business details",
};

/**
 * `/platform/businesses/:businessId/edit` — Edit business (server component).
 */
export default async function PlatformBusinessEditPage({
  params,
}: {
  params: Promise<{ businessId: string }>;
}) {
  const { businessId } = await params;
  const id = Number(businessId);
  if (!Number.isInteger(id) || id <= 0) {
    notFound();
  }

  const business = await getBusiness(id);

  return <PlatformBusinessEditClient initialBusiness={business} />;
}