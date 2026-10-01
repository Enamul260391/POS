import { create } from "zustand";

import {
  getSale as getSaleRequest,
  listAllSaleReturns,
  listSales,
  type SaleFilters,
} from "@/features/sales/services/salesService";
import type { ApiPagination } from "@/types/api";
import type { Sale } from "@/types/sale";
import type { SaleReturn } from "@/types/saleReturn";

/**
 * Sales history cache (Phase 10, Phase 25).
 *
 * Two collections, both **server-derived**:
 *
 *   sales        the page `/sales` is showing (or the single invoice
 *                `/sales/[id]` asked for)
 *   saleReturns  every return the API reported, read once and reused to net off
 *                returnable quantities and refund totals
 *
 * The store is a cache, not a source of truth: `load` replaces the page from
 * `GET /sales`, `loadSale` upserts one invoice from `GET /sales/{id}`, and
 * `addSaleReturn` prepends the document the API returned from
 * `POST /sale-returns` (which has already reversed stock inside its own
 * transaction). Nothing is computed locally except the presentation helpers in
 * `lib/returns`, which read the API's own numbers.
 *
 * `mock/sales.json` and `mock/saleReturns.json` are no longer referenced.
 */
export interface SalesStore {
  /** Sales of the current page, newest first. */
  sales: Sale[];
  /** Returns known to the client, newest first. */
  saleReturns: SaleReturn[];
  /** Pagination of the last list request, `null` before the first load. */
  pagination: ApiPagination | null;
  /** `true` while a list/detail request is in flight. */
  isLoading: boolean;
  /** `true` once at least one successful load has completed. */
  isLoaded: boolean;
  /** `true` once the return catalogue has been read at least once. */
  returnsLoaded: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;

  /** Loads one page of invoices. */
  load: (filters?: SaleFilters, page?: number) => Promise<void>;
  /** Loads every recorded return (used for refunded/returnable figures). */
  loadReturns: () => Promise<void>;
  /** Loads one invoice by id and caches it. Returns `null` when not found. */
  loadSale: (saleId: number) => Promise<Sale | null>;
  /** Upserts one invoice into the cache (e.g. the POS checkout response). */
  cacheSale: (sale: Sale) => void;
  /** Records a freshly created return. Called by the return dialog. */
  addSaleReturn: (saleReturn: SaleReturn) => void;
  /** Empties every cache, e.g. on sign-out. */
  reset: () => void;
}

/** Display-safe message of a rejection. */
function messageOf(error: unknown, fallback: string): string {
  if (
    typeof error === "object" &&
    error !== null &&
    "message" in error &&
    typeof (error as { message: unknown }).message === "string"
  ) {
    const message = (error as { message: string }).message;
    if (message.trim() !== "") {
      return message;
    }
  }
  return fallback;
}

/** The filters the last `load` used, so a refresh can repeat them. */
let lastFilters: SaleFilters | undefined;

export const useSalesStore = create<SalesStore>()((set, get) => ({
  sales: [],
  saleReturns: [],
  pagination: null,
  isLoading: false,
  isLoaded: false,
  returnsLoaded: false,
  error: null,

  async load(filters, page = 1) {
    lastFilters = filters;
    set({ isLoading: true, error: null });

    try {
      const result = await listSales({
        ...filters,
        page,
        perPage: filters?.perPage ?? 20,
      });
      set({
        sales: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load sales."),
      });
    }
  },

  async loadReturns() {
    try {
      const saleReturns = await listAllSaleReturns();
      set({ saleReturns, returnsLoaded: true });
    } catch (error) {
      set({ error: messageOf(error, "Could not load sale returns.") });
    }
  },

  async loadSale(saleId) {
    set({ isLoading: true, error: null });

    try {
      const sale = await getSaleRequest(saleId);
      get().cacheSale(sale);
      set({ isLoading: false, isLoaded: true });
      return sale;
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load the invoice."),
      });
      return null;
    }
  },

  cacheSale(sale) {
    set((state) => {
      const exists = state.sales.some((entry) => entry.id === sale.id);
      return {
        sales: exists
          ? state.sales.map((entry) => (entry.id === sale.id ? sale : entry))
          : [sale, ...state.sales],
      };
    });
  },

  addSaleReturn(saleReturn) {
    set((state) => ({ saleReturns: [saleReturn, ...state.saleReturns] }));
  },

  reset() {
    lastFilters = undefined;
    set({
      sales: [],
      saleReturns: [],
      pagination: null,
      isLoading: false,
      isLoaded: false,
      returnsLoaded: false,
      error: null,
    });
  },
}));

/** Selects the invoice list; stable reference until the next load. */
export const selectSales = (state: SalesStore): Sale[] => state.sales;

/** Selects the return list; stable reference until a new return lands. */
export const selectSaleReturns = (state: SalesStore): SaleReturn[] =>
  state.saleReturns;

/** Selects the pagination of the last list request. */
export const selectSalePagination = (state: SalesStore): ApiPagination | null =>
  state.pagination;

/** The filters of the last list request, for a manual refresh. */
export function lastSaleFilters(): SaleFilters | undefined {
  return lastFilters;
}

