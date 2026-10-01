import { apiGet, apiList, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import { todayDateString } from "@/lib/api/values";
import { calculatePurchaseTotals } from "@/features/purchases/lib/purchases";
import { toPurchase, toPurchases } from "@/features/purchases/lib/purchaseMapper";
import type { ApiError } from "@/types/api";
import type { Purchase, PurchasePayload } from "@/types/purchase";

/**
 * Purchase application service — the only module that talks to `/purchases`.
 *
 * Phase 25 replaced the mock workflow (local stock adjustment, locally
 * computed totals, a hand-maintained supplier balance) with the documented
 * API call. Everything financial and every stock movement now happens inside
 * `POST /purchases`' single server transaction:
 *
 *   the client sends *inputs* — supplier, lines (product, quantity, cost,
 *   discount), the invoice-level tax amount and the amount paid — and the API
 *   recomputes the subtotal, grand total, due amount and payment status,
 *   writes the purchase rows and appends one stock-in movement per line.
 *
 * The dialog still previews totals with the shared `calculatePurchaseTotals`,
 * and this service reuses that same calculation to translate the previewed
 * VAT percentage into the absolute `tax` figure the API expects. The
 * authoritative numbers are always the ones the API returns.
 *
 * No request carries `business_id`: Laravel derives the tenant from the bearer
 * token, and every id sent (supplier, product) is validated against it.
 */

/** Everything one line needs; mirrors the API payload's `items.*` contract. */
export interface CreatePurchaseLineInput {
  productId: number;
  /** Units to receive; must be greater than 0. */
  quantity: number;
  /** Agreed cost per unit (BDT). */
  unitCost: number;
  /** Absolute line discount (BDT), clamped to the line by the totals. */
  discount: number;
}

/** Everything an order needs, as the dialog collects it. */
export interface CreatePurchaseInput {
  supplierId: number;
  lines: CreatePurchaseLineInput[];
  /** Invoice level VAT percentage (`5` = 5%). */
  taxRate: number;
  /** Amount handed over now; clamped to the grand total. */
  paidAmount: number;
  note?: string | null;
}

export type CreatePurchaseResult =
  | { ok: true; purchase: Purchase }
  | { ok: false; message: string };

/** Query parameters accepted by `GET /purchases` (`IndexPurchaseRequest`). */
export interface PurchaseFilters {
  /** Free text over invoice number, notes and supplier name. */
  search?: string;
  supplierId?: number | null;
  status?: string;
  /** Settlement filter in the API's vocabulary (`pending`/`paid`/`partial`). */
  paymentStatus?: string;
  dateFrom?: string;
  dateTo?: string;
  page?: number;
  perPage?: number;
  sort?: string;
  direction?: "asc" | "desc";
}

/** Maps the frontend filter object onto the query string. */
function toQuery(filters: PurchaseFilters | undefined): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    supplier_id: filters.supplierId,
    status: filters.status,
    payment_status: filters.paymentStatus,
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/** Readable message for a failed create: field errors first, then the envelope. */
function messageOf(error: unknown, fallback: string): string {
  if (typeof error === "object" && error !== null) {
    const apiError = error as ApiError;
    const fieldMessages = Object.values(apiError.validationErrors ?? {})
      .flat()
      .filter((message): message is string => typeof message === "string" && message.trim() !== "");
    if (fieldMessages.length > 0) {
      return fieldMessages[0];
    }
    if (typeof apiError.message === "string" && apiError.message.trim() !== "") {
      return apiError.message;
    }
  }
  return fallback;
}

/**
 * `POST /purchases` — records the order; the API receives the stock.
 *
 * The response is the authoritative document: totals, payment status and
 * lines all come back as stored by the server transaction. Failures (422
 * validation, 409 conflicts, network) are converted into the result union the
 * dialog already understands, so a rejected order keeps the form open with a
 * readable message.
 */
export async function createPurchase(
  input: CreatePurchaseInput,
): Promise<CreatePurchaseResult> {
  try {
    // The dialog previews the VAT as a percentage; the API stores an absolute
    // amount. Same shared calculation the preview used, so preview ≡ payload.
    const totals = calculatePurchaseTotals({
      lines: input.lines,
      taxRate: input.taxRate,
      paidAmount: input.paidAmount,
    });

    const payload = await apiPost<PurchasePayload>("/purchases", {
      supplier_id: input.supplierId,
      // The form has no date field (the mock always used "now"); the order is
      // recorded for today in the browser's own time zone.
      purchase_date: todayDateString(),
      tax: totals.taxTotal,
      paid_amount: totals.paidAmount,
      notes: input.note?.trim() || null,
      items: input.lines.map((line) => ({
        product_id: line.productId,
        quantity: line.quantity,
        unit_cost: line.unitCost,
        discount: line.discount,
      })),
    });

    return { ok: true, purchase: toPurchase(payload) };
  } catch (error) {
    return {
      ok: false,
      message: messageOf(error, "Could not record the purchase. Please try again."),
    };
  }
}

/** `GET /purchases` — one page, filtered and ordered by the API. */
export async function listPurchases(
  filters?: PurchaseFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Purchase>> {
  const data = await apiList<PurchasePayload>("/purchases", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toPurchases(data.items), pagination: data.pagination };
}

/**
 * `GET /purchases/{id}` — one order with its lines and products.
 *
 * The list endpoint does not embed the lines (no N+1), so the details dialog
 * asks for the full document before opening.
 */
export async function getPurchase(
  id: number,
  config?: ApiRequestConfig,
): Promise<Purchase> {
  const payload = await apiGet<PurchasePayload>(`/purchases/${id}`, {
    signal: config?.signal,
  });
  return toPurchase(payload);
}
