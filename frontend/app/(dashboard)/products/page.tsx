import type { Metadata } from "next";

import { ProductsScreen } from "@/features/products/components/ProductsScreen";

export const metadata: Metadata = {
  title: "Products",
  description: "Product master data: pricing, categories and stock levels.",
};

/**
 * `/products` — product management (Phase 7).
 *
 * Deliberately tiny: filter state, tables and dialogs live in the products
 * feature, keeping the page component free of screen logic (same pattern as
 * `/pos`).
 */
export default function ProductsPage() {
  return (
    <>
      <h1 className="sr-only">Products</h1>
      <ProductsScreen />
    </>
  );
}
