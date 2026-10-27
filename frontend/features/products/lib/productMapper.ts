import { toMoney, toQuantity, toText } from "@/lib/api/values";
import type { Product, ProductPayload, ProductStatus, ProductUnit } from "@/types/product";

/**
 * `ProductResource` → `Product`.
 *
 * The interesting conversions are the two ledger values:
 *
 *   `current_stock`  the API's `SUM(stock_movements.quantity)` as a decimal
 *                    string. It is read as-is — the frontend never reconstructs
 *                    stock, which is exactly what the backend ledger exists for.
 *   money columns    decimal *strings* (`"12.50"`), converted once here.
 *
 * `category` is only present when the query eager-loaded the relation, so the
 * category *name* is nullable. Screens that always have the full category list
 * resolve the name themselves from `categoryId` instead of relying on this.
 */
export function toProduct(payload: ProductPayload): Product {
  return {
    id: payload.id,
    name: payload.name,
    sku: toText(payload.sku),
    barcode: toText(payload.barcode),
    categoryId: payload.category_id,
    categoryName: payload.category?.name ?? null,
    // `null` means the query did not select the aggregate; a product with no
    // ledger row legitimately holds nothing, so both read as `0`.
    stock: toQuantity(payload.current_stock),
    lowStockThreshold: payload.low_stock_threshold ?? 0,
    unit: toProductUnit(payload.unit),
    status: payload.status === "active" ? "active" : "inactive",
    image: toText(payload.image),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of products. */
export function toProducts(payloads: readonly ProductPayload[]): Product[] {
  return payloads.map(toProduct);
}

/** Every unit `StoreProductRequest` accepts, as a runtime set. */
const PRODUCT_UNITS: ReadonlySet<string> = new Set<ProductUnit>([
  "piece",
  "pack",
  "box",
  "carton",
  "dozen",
  "kilogram",
  "gram",
  "liter",
  "milliliter",
  "bottle",
  "service",
]);

/**
 * Narrows the API's `unit` string.
 *
 * The column is a free-form `varchar(20)`, so an unknown value is possible in
 * principle. Falling back to `piece` keeps the UI renderable instead of
 * throwing inside a list render.
 */
export function toProductUnit(value: string): ProductUnit {
  return PRODUCT_UNITS.has(value) ? (value as ProductUnit) : "piece";
}

/** Narrows the API's `status` string; anything unknown reads as `inactive`. */
export function toProductStatus(value: string): ProductStatus {
  return value === "active" ? "active" : "inactive";
}