import type { Category } from "@/types/category";
import type { Product, ProductStatus } from "@/types/product";

/**
 * Query helpers for the product management screens (Phase 7, Phase 25).
 *
 * Owns category lookups and the client-side filter rules so the screen
 * components stay presentational.
 *
 * Phase 25: the mock `categories.json` import is gone. Categories now come from
 * `GET /categories` and are passed in by the screen, because the API's
 * `categories` table is **flat** (no `parent_id`): the one-level tree walk this
 * module used to do has no data behind it, and a product now matches exactly
 * one category.
 *
 * The helpers take the list explicitly rather than reading a module-level
 * array, so two screens can never disagree about the category catalogue.
 */

/** Display name of a category id; `—` when unknown. */
export function categoryName(
  categories: readonly Category[],
  categoryId: number | null,
): string {
  if (categoryId === null) {
    return "—";
  }
  return categories.find((category) => category.id === categoryId)?.name ?? "—";
}

/** Display path of a category, e.g. `Cold Drinks`. */
export function categoryPath(
  categories: readonly Category[],
  categoryId: number | null,
): string {
  return categoryName(categories, categoryId);
}

/**
 * Options for category selects.
 *
 * All categories are offered — this is the management area, where inactive
 * categories must stay assignable (the POS tabs remain active-only).
 */
export function categoryOptions(
  categories: readonly Category[],
): readonly { value: number; label: string }[] {
  return categories.map((category) => ({
    value: category.id,
    label: category.name,
  }));
}

/** Active status filter options, preceded by "All". */
export const STATUS_FILTER_OPTIONS: readonly { value: "all" | ProductStatus; label: string }[] = [
  { value: "all", label: "All statuses" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

/** Current filter state of the product table. */
export interface ProductTableFilters {
  /** Free text over name, SKU and barcode; empty means no text filter. */
  query: string;
  /** Category; `null` means all categories. */
  categoryId: number | null;
  /** Status filter; `"all"` disables it. */
  status: "all" | ProductStatus;
}

/**
 * Case-insensitive search over name, SKU and barcode, plus category and
 * status filters — one pass so the table can memoise on the result.
 *
 * Phase 25: the category match is an exact id comparison. The API's
 * `categories` table has no `parent_id`, so the tree walk the mock catalogue
 * needed has no data behind it any more.
 */
export function filterProducts(
  products: readonly Product[],
  filters: ProductTableFilters,
): Product[] {
  const needle = filters.query.trim().toLowerCase();
  return products.filter((product) => {
    if (filters.status !== "all" && product.status !== filters.status) {
      return false;
    }
    if (filters.categoryId !== null && product.categoryId !== filters.categoryId) {
      return false;
    }
    if (needle !== "") {
      const barcode = product.barcode ?? "";
      const sku = product.sku ?? "";
      const matches =
        product.name.toLowerCase().includes(needle) ||
        sku.toLowerCase().includes(needle) ||
        barcode.includes(needle);
      if (!matches) {
        return false;
      }
    }
    return true;
  });
}

/** Visual stock state of a product, derived from its master fields only. */
export type StockLevel = "in" | "low" | "out" | "untracked";

/** Services are not stock tracked; their quantity is meaningless. */
export function isStockTracked(product: Product): boolean {
  return product.unit !== "service";
}

/**
 * Single source of stock wording: `out` (nothing left), `low` (at or below
 * the threshold), `in`, or `untracked` for services. Shared by the products
 * and inventory tables so both always agree.
 */
export function stockLevel(product: Product): StockLevel {
  if (!isStockTracked(product)) {
    return "untracked";
  }
  if (product.stock <= 0) {
    return "out";
  }
  if (product.stock <= product.lowStockThreshold) {
    return "low";
  }
  return "in";
}
