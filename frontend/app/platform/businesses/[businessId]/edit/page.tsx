import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { PlatformBusinessEditClient } from "@/features/platform/components/PlatformBusinessEditClient";

export const metadata: Metadata = {
  title: "Edit Business",
  description: "Edit business details",
};

/**
 * `/platform/businesses/:businessId/edit` — Edit business (server component).
 *
 * The route shell only validates the id segment and owns `metadata`; the
 * business itself is loaded from `GET /platform/businesses/{id}` inside the
 * client component, because the Sanctum token lives in browser storage and is
 * therefore unavailable during SSR. Fetching here would send the request
 * without an `Authorization` header and Laravel would answer `401`, throwing
 * during server render. This mirrors the view page (`[businessId]/page.tsx`).
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

  return <PlatformBusinessEditClient businessId={id} />;
}