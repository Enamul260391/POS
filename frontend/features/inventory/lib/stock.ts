import { stockLevel } from "@/features/products/lib/products";
import type { Product } from "@/types/product";

/**
 * Inventory domain types and aggregation helpers (Phase 7).
 *
 * Kept conceptually apart from product master data: everything here answers
 * "how much stock is there and how did it change", while
 * `features/products` answers "what is the product". `StockMovement` mirrors
 * the future Laravel `stock_movements` row field for field, so the mock
 * placeholder list is replaced by an API response without UI changes.
 */

/** How a *manual* adjustment changes quantity — the dialog's vocabulary. */
export type StockAdjustmentType = "receive" | "remove" | "set";

/**
 * Every type the ledger can hold (`StockMovement::TYPES` as the API exposes it).
 *
 * Phase 25: the ledger is no longer a list of manual adjustments only. Purchases,
 * sales and sale returns write their own rows, so the history has to be able to
 * label them — and crucially to keep them **read-only** here, because their
 * quantity is owned by the document that created them.
 */
export type StockMovementType =
  | StockAdjustmentType
  | "purchase"
  | "sale"
  | "sale_return";

/** One stock movement, client-side mirror of a `stock_movements` row. */
export interface StockMovement {
  /** Server id of the ledger row, namespaced so it can never clash. */
  id: string;
  productId: number;
  /** Name snapshot so history stays readable after a rename. */
  productName: string;
  sku: string;
  type: StockMovementType;
  /** Units moved, always positive; `delta` carries the sign. */
  quantity: number;
  /** Signed change the ledger applied. */
  delta: number;
  /**
   * Balance after the movement.
   *
   * `null` for a historic row read from the log: the ledger stores the change,
   * not a running balance, so the API reports no per-row balance. It is filled
   * in for a movement this session just wrote, because that response carries
   * the post-write stock.
   */
  stockAfter: number | null;
  note: string | null;
  /** ISO 8601 timestamp of the adjustment. */
  occurredAt: string;
  /** Display name of the user who recorded it. */
  recordedBy: string;
}

/** Adjustment types with the labels and hints shown in the dialog. */
export const ADJUSTMENT_TYPE_OPTIONS: readonly {
  value: StockAdjustmentType;
  label: string;
  hint: string;
}[] = [
  { value: "receive", label: "Receive stock", hint: "Delivery or customer return" },
  { value: "remove", label: "Remove stock", hint: "Damage, expiry or supplier return" },
  { value: "set", label: "Set exact count", hint: "Physical recount overrides the system" },
];

/** Aggregated stock picture powering the inventory stat cards and tabs. */
export interface InventorySummary {
  /** Products whose stock is tracked (everything except services). */
  trackedCount: number;
  /** Tracked products currently holding stock. */
  productsInStock: number;
  /** Sum of tracked stock quantities (mixed units — an order of magnitude indicator). */
  totalUnits: number;
  /** Value of tracked stock at cost: not computed from product-level pricing
   * (removed); inventory valuation comes from purchase/batch data. */
  stockValue: number;
  /** Tracked products at or below their low-stock threshold (and not empty). */
  lowStock: Product[];
  /** Tracked products with no stock left. */
  outOfStock: Product[];
}

/** One pass over the catalogue; the screens memoise on the products array. */
export function summarizeInventory(products: readonly Product[]): InventorySummary {
  const summary: InventorySummary = {
    trackedCount: 0,
    productsInStock: 0,
    totalUnits: 0,
    stockValue: 0,
    lowStock: [],
    outOfStock: [],
  };

  for (const product of products) {
    if (product.unit === "service") {
      continue;
    }
    summary.trackedCount += 1;
    summary.totalUnits += product.stock;
    // stockValue intentionally 0: product-level cost_price was removed; valuation
    // comes from batch/purchase data, not the product row.

    const level = stockLevel(product);
    if (level === "out") {
      summary.outOfStock.push(product);
    } else {
      summary.productsInStock += 1;
      if (level === "low") {
        summary.lowStock.push(product);
      }
    }
  }

  return summary;
}

/**
 * Stock that an adjustment would produce — used for the dialog preview so
 * the cashier sees the outcome before committing. Returns `null` when the
 * inputs cannot produce a valid quantity.
 */
export function previewStock(
  current: number,
  type: StockAdjustmentType,
  quantity: number,
): number | null {
  if (!Number.isFinite(quantity) || quantity < 0) {
    return null;
  }
  switch (type) {
    case "receive":
      return quantity > 0 ? roundStock(current + quantity) : null;
    case "remove":
      return quantity > 0 && quantity <= current ? roundStock(current - quantity) : null;
    case "set":
      return roundStock(quantity);
  }
}

/** Stock keeps the three decimals fractional units allow. */
function roundStock(stock: number): number {
  return Math.round(stock * 1000) / 1000;
}
