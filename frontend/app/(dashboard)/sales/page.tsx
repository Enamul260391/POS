import type { Metadata } from "next";

import { SalesScreen } from "@/features/sales/components/SalesScreen";

export const metadata: Metadata = {
  title: "Sales",
  description: "Sales history: invoices, payments and customer returns.",
};

/**
 * `/sales` — sales history (Phase 10).
 *
 * Deliberately tiny: filtering, the table and the return dialog live in the
 * sales feature. A return runs through `salesService`, which reverses stock
 * through the existing inventory boundary — the page itself never touches
 * stock (same pattern as `/purchases` and `/customers`).
 */
export default function SalesPage() {
  return (
    <>
      <h1 className="sr-only">Sales</h1>
      <SalesScreen />
    </>
  );
}
