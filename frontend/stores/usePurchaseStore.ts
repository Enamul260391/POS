import { create } from "zustand";

import {
  getPurchase as getPurchaseRequest,
  listPurchases,
  type PurchaseFilters,
} from "@/features/purchases/services/purchaseService";
import type { ApiPagination } from "@/types/api";
import type { Purchase } from "@/types/purchase";

/**
 * Purchase history cache (Phase 9, Phase 25).
 *
 * Phase 25 removed the mock seed and the hand-rolled writers. The store is a
 * cache of the page `/purchases` is showing, hydrated from `GET /purchases`;
 * `addPurchase` prepends the authoritative document the API returned from
 * `POST /purchases` (which has already written the stock-in movements inside
 * its own transaction), and `loadPurchase` replaces one row with the detail
 * response so the dialog can show the lines the list endpoint omits.
 *
 * Nothing here computes totals, moves stock or touches the supplier balance —
 * the backend owns all of it.
 */
export interface PurchaseStore {
  /** Purchases of the current page, newest first. */
  purchases: Purchase[];
  /** Pagination of the last list request, `null` before the first load. */
  pagination: ApiPagination | null;
  /** `true` while a list/detail request is in flight. */
  isLoading: boolean;
  /** `true` once at least one successful load has completed. */
  isLoaded: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;

  /** Loads one page of purchases (filters are applied server-side). */
  load: (filters?: PurchaseFilters, page?: number) => Promise<void>;
  /** Loads one purchase with its lines and replaces the cached row. */
  loadPurchase: (purchaseId: number) => Promise<Purchase | null>;
  /** Prepends a newly recorded purchase. Called after a successful create. */
  addPurchase: (purchase: Purchase) => void;
  /** Empties the cache, e.g. on sign-out. */
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
let lastFilters: PurchaseFilters | undefined;

export const usePurchaseStore = create<PurchaseStore>()((set) => ({
  purchases: [],
  pagination: null,
  isLoading: false,
  isLoaded: false,
  error: null,

  async load(filters, page = 1) {
    lastFilters = filters;
    set({ isLoading: true, error: null });

    try {
      const result = await listPurchases({
        ...filters,
        page,
        perPage: filters?.perPage ?? 20,
      });
      set({
        purchases: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load purchases."),
      });
    }
  },

  async loadPurchase(purchaseId) {
    set({ isLoading: true, error: null });

    try {
      const purchase = await getPurchaseRequest(purchaseId);
      set((state) => ({
        purchases: state.purchases.map((entry) =>
          entry.id === purchaseId ? purchase : entry,
        ),
        isLoading: false,
        isLoaded: true,
      }));
      return purchase;
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load the purchase."),
      });
      return null;
    }
  },

  addPurchase(purchase) {
    set((state) => ({ purchases: [purchase, ...state.purchases] }));
  },

  reset() {
    lastFilters = undefined;
    set({
      purchases: [],
      pagination: null,
      isLoading: false,
      isLoaded: false,
      error: null,
    });
  },
}));

/** Selects the purchase list; stable reference until the next load. */
export const selectPurchases = (state: PurchaseStore): Purchase[] =>
  state.purchases;

/** The filters of the last list request, for a manual refresh. */
export function lastPurchaseFilters(): PurchaseFilters | undefined {
  return lastFilters;
}