import { apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Payment, PaymentPayload } from "@/types/payment";
import type { Sale, SalePayload } from "@/types/sale";
import type { SaleReturn, SaleReturnPayload } from "@/types/saleReturn";

import { toPayment, toSale, toSales } from "../lib/saleMapper";
import { toSaleReturn, toSaleReturns } from "../lib/saleReturnMapper";

/**
 * Sales application service — the only module that talks to `/sales` and
 * `/sale-returns`.
 *
 * Phase 25 replaced the mock bodies with the documented API calls. Two rules
 * drove the shape of this file:
 *
 *   - The backend owns every total. `POST /sales` recalculates the lines, the
 *     subtotal, the discount, the tax, the grand total and the due amount, then
 *     writes the stock-out movements and (for a cash payment) the cash-register
 *     entry in one transaction. The client therefore sends *inputs* — lines and
 *     at most a discount, a tax amount and the amount paid — never totals.
 *   - The backend owns stock. A return reverses inventory inside the same
 *     transaction that writes the return document, so this service never
 *     touches a product's stock.
 *
 * No request carries `business_id`: Laravel resolves the tenant from the bearer
 * token, and every id sent (customer, product, sale, sale item) is validated
 * against that tenant.
 */

/**
 * Payment methods the API accepts.
 *
 * `mobile_banking` and `store_credit` exist in the UI but not in
 * `Payment::PAYMENT_METHODS`; callers map them before reaching this service
 * (see `@/features/pos/lib/paymentMapping`).
 */
export type ApiPaymentMethod = "cash" | "card" | "bank" | "cheque" | "due";

/** Query parameters accepted by `GET /sales` (`IndexSaleRequest`). */
export interface SaleFilters {
  /** Free text over invoice number, notes and customer name. */
  search?: string;
  customerId?: number | null;
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

/** Query parameters accepted by `GET /sale-returns` (`IndexSaleReturnRequest`). */
export interface SaleReturnFilters {
  search?: string;
  saleId?: number;
  status?: string;
  page?: number;
  perPage?: number;
}

/** One line of a sale the client is allowed to send (`StoreSaleRequest`). */
export interface SaleItemInput {
  productId: number;
  quantity: number;
  /** Defaults to the product's selling price on the server when omitted. */
  unitPrice?: number | null;
  /**
   * Batch row the line was allocated from. `null` means the automatic
   * price selection applied (the backend splits across batches with FIFO).
   * Omitted entirely when the frontend had no batch allocation.
   */
  batch_id?: number | null;
  /** Absolute discount for this line. */
  discount?: number | null;
  /** Tax amount for this line (per-product VAT from the cart). */
  tax?: number | null;
}

/**
 * Body of `POST /sales` (`StoreSaleRequest`).
 *
 * There is deliberately no `invoiceNumber`: the API owns the invoice number,
 * allocates it server side from the business' own sequence and rejects the
 * field outright (`prohibited`), so the client can neither choose nor guess it.
 * The number the customer was charged arrives back on the created `Sale`.
 */
export interface SaleInput {
  customerId?: number | null;
  /** `YYYY-MM-DD`. */
  saleDate: string;
  /** Invoice level discount (line discounts travel inside `items`). */
  discount?: number | null;
  /** Invoice level tax (line taxes travel inside `items`). */
  tax?: number | null;
  /** Amount handed over; the API records a payment when it is above zero. */
  paidAmount?: number | null;
  paymentMethod?: ApiPaymentMethod | null;
  paymentReference?: string | null;
  notes?: string | null;
  items: SaleItemInput[];
}

/** Body of `POST /sales/{sale}/payments` (`StorePaymentRequest`). */
export interface PaymentInput {
  amount: number;
  paymentMethod: ApiPaymentMethod;
  reference?: string | null;
  paidAt?: string | null;
  notes?: string | null;
}

/** One line of a return the client may send (`StoreSaleReturnRequest`). */
export interface SaleReturnLineInput {
  saleItemId: number;
  quantity: number;
}

/** Body of `POST /sale-returns` (`StoreSaleReturnRequest`). */
export interface SaleReturnInput {
  saleId: number;
  /** Free-text reason for the whole return (max 2000). */
  reason?: string | null;
  returnDate?: string | null;
  returnNumber?: string | null;
  /** Returned lines, each pointing at an original sale item. */
  lines: SaleReturnLineInput[];
}

/** Maps the frontend filter object onto the query string. */
function toSaleQuery(filters: SaleFilters | undefined): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    customer_id: filters.customerId,
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

/** `GET /sales` — one page, filtered and ordered by the API. */
export async function listSales(
  filters?: SaleFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Sale>> {
  const data = await apiList<SalePayload>("/sales", {
    params: toSaleQuery(filters),
    signal: config?.signal,
  });

  return { items: toSales(data.items), pagination: data.pagination };
}

/** `GET /sales/{id}` — one invoice with its lines, payments and customer. */
export async function getSale(
  id: number,
  config?: ApiRequestConfig,
): Promise<Sale> {
  const payload = await apiGet<SalePayload>(`/sales/${id}`, {
    signal: config?.signal,
  });
  return toSale(payload);
}

/**
 * `POST /sales` — the POS checkout.
 *
 * The response is the authoritative sale (the API re-reads it with its lines,
 * payments and customer), so the receipt and the confirmation are built from it
 * rather than from the cart.
 */
export async function createSale(input: SaleInput): Promise<Sale> {
  const payload = await apiPost<SalePayload>("/sales", {
    customer_id: input.customerId ?? null,
    // `invoice_number` is not sent: the API is its only authority.
    sale_date: input.saleDate,
    discount: input.discount ?? null,
    tax: input.tax ?? null,
    paid_amount: input.paidAmount ?? null,
    payment_method: input.paymentMethod ?? null,
    payment_reference: input.paymentReference ?? null,
    notes: input.notes ?? null,
    items: input.items.map((item) => ({
      product_id: item.productId,
      quantity: item.quantity,
      unit_price: item.unitPrice ?? null,
      discount: item.discount ?? null,
      tax: item.tax ?? null,
      ...(item.batch_id != null ? { batch_id: item.batch_id } : {}),
    })),
  });

  return toSale(payload);
}

/** `POST /sales/{sale}/payments` — settles (part of) an outstanding invoice. */
export async function recordPayment(
  saleId: number,
  input: PaymentInput,
): Promise<Payment> {
  const payload = await apiPost<PaymentPayload>(`/sales/${saleId}/payments`, {
    amount: input.amount,
    payment_method: input.paymentMethod,
    reference: input.reference ?? null,
    paid_at: input.paidAt ?? null,
    notes: input.notes ?? null,
  });

  return toPayment(payload);
}

/**
 * `PATCH /sales/{sale}` — sale date and notes only.
 *
 * `invoiceNumber` is gone: the API allocates the number once, at creation, and
 * rejects the key on an edit (`prohibited`) so an invoice identity can never be
 * rewritten or cleared after the fact.
 */
export async function updateSale(
  saleId: number,
  input: { saleDate?: string; notes?: string | null },
): Promise<Sale> {
  const payload = await apiPatch<SalePayload>(`/sales/${saleId}`, {
    sale_date: input.saleDate,
    notes: input.notes ?? null,
  });

  return toSale(payload);
}

/** `GET /sale-returns` — one page of returns. */
export async function listSaleReturns(
  filters?: SaleReturnFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<SaleReturn>> {
  const data = await apiList<SaleReturnPayload>("/sale-returns", {
    params:
      filters === undefined
        ? undefined
        : {
            search: filters.search,
            sale_id: filters.saleId,
            status: filters.status,
            page: filters.page,
            per_page: filters.perPage,
          },
    signal: config?.signal,
  });

  return { items: toSaleReturns(data.items), pagination: data.pagination };
}

/**
 * `GET /sale-returns` walked page by page.
 *
 * The sales table needs to know how much was refunded per invoice and which
 * lines can still come back, and the API offers no per-sale aggregate. Returns
 * are far rarer than sales (one row per customer return, not per invoice), so
 * they are read once and cached, instead of firing one request per visible row.
 * The page size is the endpoint's maximum, so this is one request for a small
 * catalogue of returns and a handful for a busy store.
 */
export async function listAllSaleReturns(
  config?: ApiRequestConfig,
): Promise<SaleReturn[]> {
  const perPage = 100;
  const first = await listSaleReturns({ page: 1, perPage }, { signal: config?.signal });

  if (first.pagination.last_page <= 1) {
    return first.items;
  }

  const rest = await Promise.all(
    Array.from({ length: first.pagination.last_page - 1 }, (_, index) =>
      listSaleReturns({ page: index + 2, perPage }, { signal: config?.signal }),
    ),
  );

  return [...first.items, ...rest.flatMap((page) => page.items)];
}

/**
 * `POST /sale-returns` — records a return and lets the API reverse stock.
 *
 * A rejection is a class of `ApiError`, so callers can apply field errors
 * through `@/lib/api/validation` exactly like the other forms.
 */
export async function createSaleReturn(input: SaleReturnInput): Promise<SaleReturn> {
  const payload = await apiPost<SaleReturnPayload>("/sale-returns", {
    sale_id: input.saleId,
    return_date: input.returnDate ?? null,
    return_number: input.returnNumber ?? null,
    reason: input.reason?.trim() || null,
    items: input.lines.map((line) => ({
      sale_item_id: line.saleItemId,
      quantity: line.quantity,
    })),
  });

  return toSaleReturn(payload);
}
