import type { Metadata } from "next";

import { PurchasesScreen } from "@/features/purchases/components/PurchasesScreen";

export const metadata: Metadata = {
  title: "Purchases",
  description: "Supplier orders: items, totals, payment status and stock receipts.",
};

/**
 * `/purchases` — purchase management (Phase 9).
 *
 * Deliberately tiny: filter state, table and dialogs live in the purchases
 * feature. Saving a purchase runs through `purchaseService`, which receives
 * stock through the existing inventory boundary — the page itself never
 * touches stock (same pattern as `/customers` and `/products`).
 */
export default function PurchasesPage() {
  return (
    <>
      <h1 className="sr-only">Purchases</h1>
      <PurchasesScreen />
    </>
  );
}