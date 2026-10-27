/**
 * Catalogue product sold through the POS terminal.
 *
 * Conventions used by every domain type in this folder:
 * - Field names are camelCase. The Laravel API Resources are `snake_case`, and
 *   the conversion happens once, in the mappers under `features/*\/lib\/`
 *   (`toProduct`), never inside a component.
 * - Monetary values are plain numbers in the store currency (BDT). Laravel
 *   serialises them as decimal *strings* (`"12.50"`); `toMoney` converts once,
 *   at the boundary.
 * - `stock` is the **ledger** balance (`SUM(stock_movements.quantity)`) the API
 *   reports as `current_stock`. The frontend never recomputes it.
 * - Dates are ISO 8601 strings, exactly as the resource serialises them.
 */

/** Unit of measure a product is sold in (also used for quantity display). */
export type ProductUnit =
  | "piece"
  | "pack"
  | "box"
  | "carton"
  | "dozen"
  | "kilogram"
  | "gram"
  | "liter"
  | "milliliter"
  | "bottle"
  | "service";

/**
 * Lifecycle state of a product.
 * - `active`    sellable in the POS terminal
 * - `inactive`  temporarily hidden, still restockable
 *
 * Phase 25: `discontinued` was removed because `Product::STATUSES` only allows
 * `active` / `inactive`; keeping it would let the form offer a value the API
 * answers with a 422.
 */
export type ProductStatus = "active" | "inactive";

export interface Product {
  /** Primary key. */
  id: number;
  /** Display name, e.g. "Coca-Cola 500ml PET Bottle". */
  name: string;
  /** Stock keeping unit. `null` when the product was created without one. */
  sku: string | null;
  /** Scanned barcode (EAN-13 / UPC). `null` when the product has none. */
  barcode: string | null;
  /** Id of the owning category, `null` when uncategorised. */
  categoryId: number | null;
  /** Category name, when the API embedded the relation. */
  categoryName: string | null;
  /**
   * Quantity currently available, expressed in `unit`.
   *
   * This is the backend ledger's answer (`current_stock`), never a value the
   * frontend derived. `0` for services, which hold no stock.
   */
  stock: number;
  /** Stock level at or below which the product counts as low stock. */
  lowStockThreshold: number;
  /** Unit of measure the product is sold in. */
  unit: ProductUnit;
  /** Only `active` products can be added to a cart. */
  status: ProductStatus;
  /** Storage path or public URL of the product image. */
  image: string | null;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Raw `ProductResource` payload.
 *
 * Money is a decimal string, `current_stock` is a ledger decimal string (or
 * `null` when the query did not select the aggregate), and `category` is only
 * present when the relation was eager loaded.
 */
export interface ProductPayload {
  id: number;
  category_id: number | null;
  name: string;
  sku: string | null;
  barcode: string | null;
  unit: string;
  low_stock_threshold: number;
  image: string | null;
  status: string;
  current_stock: string | null;
  category?: { id: number; name: string; slug: string } | null;
  created_at: string;
  updated_at: string;
}
