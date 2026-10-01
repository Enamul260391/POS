import type { Metadata } from "next";

import { ExpensesScreen } from "@/features/expenses/components/ExpensesScreen";

export const metadata: Metadata = {
  title: "Expenses",
  description: "Shop spending: categories, amounts, dates and payment methods.",
};

/**
 * `/expenses` — expense ledger (Phase 11, mock).
 * Tiny page: state, table and dialogs live in the expenses feature.
 * The store owns create/update; delete stays a placeholder until Laravel
 * owns the audit trail (same pattern as `/purchases`).
 */
export default function ExpensesPage() {
  return (
    <>
      <h1 className="sr-only">Expenses</h1>
      <ExpensesScreen />
    </>
  );
}
