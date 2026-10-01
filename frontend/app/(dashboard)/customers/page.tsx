import type { Metadata } from "next";

import { CustomersScreen } from "@/features/customers/components/CustomersScreen";

export const metadata: Metadata = {
  title: "Customers",
  description: "Customer directory: contact details, purchases and outstanding balances.",
};

/**
 * `/customers` — customer management (Phase 8).
 *
 * Deliberately tiny: filter state, table and dialogs live in the customers
 * feature, keeping the page component free of screen logic (same pattern as
 * `/products` and `/inventory`).
 */
export default function CustomersPage() {
  return (
    <>
      <h1 className="sr-only">Customers</h1>
      <CustomersScreen />
    </>
  );
}