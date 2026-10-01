import type { Metadata } from "next";

import { SuppliersScreen } from "@/features/suppliers/components/SuppliersScreen";

export const metadata: Metadata = {
  title: "Suppliers",
  description: "Supplier directory: contact details and outstanding balances.",
};

/**
 * `/suppliers` — supplier management (Phase 9).
 *
 * Deliberately tiny: filter state, table and dialogs live in the suppliers
 * feature, keeping the page component free of screen logic (same pattern as
 * `/customers` and `/products`).
 */
export default function SuppliersPage() {
  return (
    <>
      <h1 className="sr-only">Suppliers</h1>
      <SuppliersScreen />
    </>
  );
}