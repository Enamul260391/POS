import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SaleDetailsScreen } from "@/features/sales/components/SaleDetailsScreen";

/**
 * `/sales/[id]` — one invoice (Phase 10, Phase 25).
 *
 * A thin route shell: the page validates the id segment and owns `metadata`,
 * while the invoice itself is loaded from `GET /sales/{id}` inside
 * `SaleDetailsScreen`. Nothing about the invoice is known at build time any
 * more — `mock/sales.json` is gone — so the route is rendered on demand and
 * the client screen owns the fetch, the loading state and the not-found state.
 */
export const metadata: Metadata = {
  title: "Sales details",
  description: "Sales invoice: items, totals, payments and returns.",
};

export default async function SaleDetailsPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const saleId = Number(id);
  if (!Number.isInteger(saleId) || saleId <= 0) {
    notFound();
  }

  return (
    <>
      <h1 className="sr-only">Sales details</h1>
      <SaleDetailsScreen saleId={saleId} />
    </>
  );
}
