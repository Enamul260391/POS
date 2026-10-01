import type { Metadata } from "next";

import { InventoryScreen } from "@/features/inventory/components/InventoryScreen";

export const metadata: Metadata = {
  title: "Inventory",
  description: "Stock levels, low stock alerts, adjustments and movement history.",
};

/**
 * `/inventory` — stock quantities and movements (Phase 7).
 *
 * Deliberately tiny: summary, tabs, tables and the adjustment dialog live in
 * the inventory feature, keeping the page component free of screen logic
 * (same pattern as `/products` and `/pos`).
 */
export default function InventoryPage() {
  return (
    <>
      <h1 className="sr-only">Inventory</h1>
      <InventoryScreen />
    </>
  );
}