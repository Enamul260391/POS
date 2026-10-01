import { apiGet, apiList, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import { toQuantity, toText } from "@/lib/api/values";
import type { ProductPayload } from "@/types/product";

import { toProducts } from "../../products/lib/productMapper";
import type { StockAdjustmentType, StockMovement } from "../lib/stock";

/**
 * Stock movement service — the only module that talks to `/stock-movements`.
 *
 * Phase 25: this used to mutate the product store in memory. It now performs
 * the documented API call and reports back what Laravel decided:
 *
 *   POST /products/{product}/stock-adjustments   manual receive / remove / set
 *   GET  /stock-movements                        the ledger, newest first
 *
 * The ledger is the single source of truth for on-hand quantity. The response
 * carries the product *after* the adjustment, so the caller updates its cached
 * row from the server's own numbers rather than adding the delta itself.
 */

/** Everything an adjustment needs; mirrors `StockAdjustmentRequest`. */
export interface AdjustStockInput {
  productId: number;
  type: StockAdjustmentType;
  /** Units to receive/remove; for `set` the counted quantity. */
  quantity: number;
  /** Free-text reason printed with the movement. */
  note?: string | null;
}

export type AdjustStockResult =
  | { ok: true; movement: StockMovement; product: ProductPayload }
  | { ok: false; message: string };

/** One row of the stock-movement ledger, exactly as `StockMovementResource` sends it. */
export interface StockMovementPayload {
  id: number;
  product_id: number;
  type: string;
  quantity: string;
  reference_type: string | null;
  reference_id: number | null;
  note: string | null;
  created_by: number | null;
  creator: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

/** `data` of a stock adjustment response. */
interface AdjustStockPayload {
  product: ProductPayload;
  movement: StockMovementPayload;
  stock_before: string;
  current_stock: string;
}

/** Query parameters accepted by `GET /stock-movements`. */
export interface StockMovementFilters {
  productId?: number;
  type?: string;
  dateFrom?: string;
  dateTo?: string;
  page?: number;
  perPage?: number;
}

/** Movement types the log filter accepts (`StockMovement::LOG_TYPES`). */
const MOVEMENT_TYPES: ReadonlySet<string> = new Set([
  "receive",
  "remove",
  "set",
  "purchase",
  "sale",
  "sale_return",
]);

/**
 * Manual adjustment types (`StockMovement::ADJUSTMENT_TYPES`).
 *
 * The dialog only offers these three; `purchase` / `sale` / `sale_return` rows
 * are written by their own document flows.
 */
const ADJUSTMENT_TYPES: ReadonlySet<string> = new Set(["receive", "remove", "set"]);

/**
 * Converts a ledger row into the UI's movement shape.
 *
 * `delta` is the **signed** change the ledger stored, which is what the history
 * list shows. `stockAfter` is the resulting balance: on a write the API returns
 * it, while a historic log row has no running balance attached, so the caller
 * leaves it `0` and the list shows the change only.
 */
function toMovement(
  payload: StockMovementPayload,
  productName: string,
  sku: string | null,
  currentStock: number | null,
): StockMovement {
  const delta = toQuantity(payload.quantity);
  return {
    id: `mov-${payload.id}`,
    productId: payload.product_id,
    productName,
    sku: sku ?? "—",
    type: MOVEMENT_TYPES.has(payload.type)
      ? (payload.type as StockMovement["type"])
      : "receive",
    quantity: Math.abs(delta),
    delta,
    stockAfter: currentStock ?? 0,
    note: toText(payload.note),
    occurredAt: payload.created_at,
    recordedBy: payload.creator?.name ?? "—",
  };
}

/**
 * `POST /products/{product}/stock-adjustments`.
 *
 * The API validates the same rules the mock did (receive/remove need a positive
 * quantity, remove cannot exceed the balance, services are not stock tracked)
 * and answers a business-rule failure — e.g. removing more than is on hand — with
 * its own wording, which is surfaced verbatim rather than replaced.
 */
export async function adjustStock(input: AdjustStockInput): Promise<AdjustStockResult> {
  if (!ADJUSTMENT_TYPES.has(input.type)) {
    return { ok: false, message: "Unknown adjustment type." };
  }

  try {
    const payload = await apiPost<AdjustStockPayload>(
      `/products/${input.productId}/stock-adjustments`,
      {
        type: input.type,
        quantity: input.quantity,
        note: input.note?.trim() || null,
      },
    );

    return {
      ok: true,
      product: payload.product,
      movement: toMovement(
        payload.movement,
        payload.product.name,
        payload.product.sku,
        toQuantity(payload.current_stock),
      ),
    };
  } catch (error) {
    return {
      ok: false,
      message:
        typeof error === "object" && error !== null && "message" in error
          ? String((error as { message: unknown }).message)
          : "Could not adjust stock. Try again.",
    };
  }
}

/**
 * `GET /stock-movements` — the ledger, newest first.
 *
 * The product name is resolved from the catalogue the caller already holds, so
 * reading the log costs one request instead of one per movement.
 */
export async function listStockMovements(
  filters: StockMovementFilters,
  products: readonly { id: number; name: string; sku: string | null }[],
  config?: ApiRequestConfig,
): Promise<PaginatedData<StockMovement>> {
  const data = await apiList<StockMovementPayload>("/stock-movements", {
    params: {
      product_id: filters.productId,
      type: filters.type,
      date_from: filters.dateFrom,
      date_to: filters.dateTo,
      page: filters.page,
      per_page: filters.perPage,
    },
    signal: config?.signal,
  });

  const byId = new Map(products.map((product) => [product.id, product]));

  return {
    items: data.items.map((payload) => {
      const product = byId.get(payload.product_id);
      return toMovement(
        payload,
        product?.name ?? `Product #${payload.product_id}`,
        product?.sku ?? null,
        null,
      );
    }),
    pagination: data.pagination,
  };
}

/**
 * Re-reads one product after an adjustment.
 *
 * Exposed so a caller can refresh a single cached row (and its ledger balance)
 * without reloading the whole catalogue.
 */
export async function refreshProductStock(
  productId: number,
  config?: ApiRequestConfig,
) {
  const payload = await apiGet<ProductPayload>(`/products/${productId}`, {
    signal: config?.signal,
  });
  return toProducts([payload])[0];
}
