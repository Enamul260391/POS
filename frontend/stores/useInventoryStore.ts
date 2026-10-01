import { create } from "zustand";

import {
  listStockMovements,
  type StockMovementFilters,
} from "@/features/inventory/services/stockService";
import { toProduct } from "@/features/products/lib/productMapper";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { ProductPayload } from "@/types/product";

import type { StockMovement } from "@/features/inventory/lib/stock";

/**
 * Stock movement history (Phase 7, Phase 25).
 *
 * Conceptually separate from the product master: products answer "what is the
 * stock now", this answers "how did it get here" — the client-side mirror of
 * Laravel's `stock_movements` table.
 *
 * Phase 25 replaced the two seeded mock rows with `GET /stock-movements`. The
 * list is deliberately **not** accumulated here: it is loaded on demand by the
 * inventory screen, one page at a time, because a ledger grows without bound and
 * holding every row in a global store is exactly the kind of permanent cache
 * this phase is meant to remove.
 */
export interface InventoryStore {
  /** Movements currently on screen; empty before the first `load`. */
  movements: StockMovement[];
  /** `true` while the ledger is being read. */
  isLoading: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;
  /** `true` when more movements exist beyond the loaded page. */
  hasMore: boolean;
  /** Page currently loaded (1-based). */
  page: number;
  /** Total number of ledger rows for the current filter. */
  total: number;

  /** Loads one page of the ledger, replacing what is on screen. */
  load: (filters: StockMovementFilters, page?: number) => Promise<void>;
  /** Clears the list, e.g. when the inventory screen unmounts. */
  reset: () => void;
  /** Prepends a movement recorded by an adjustment in this session. */
  recordMovement: (movement: StockMovement) => void;
  /**
   * Replaces one cached product row with the server's post-write version.
   *
   * Called after an adjustment: the API returns the product *after* the ledger
   * write, so the displayed balance is the server's, never a locally computed
   * `stock ± quantity`.
   */
  applyProductUpdate: (payload: ProductPayload) => void;
}

export const useInventoryStore = create<InventoryStore>()((set) => ({
  movements: [],
  isLoading: false,
  error: null,
  hasMore: false,
  page: 1,
  total: 0,

  async load(filters, page = 1) {
    set({ isLoading: true, error: null });
    try {
      const products = useCatalogueStore.getState().products;
      const result = await listStockMovements(
        { ...filters, page, perPage: filters.perPage ?? 20 },
        products,
      );
      set({
        movements: result.items,
        page: result.pagination.current_page,
        total: result.pagination.total,
        hasMore: result.pagination.current_page < result.pagination.last_page,
        isLoading: false,
      });
    } catch (error) {
      set({
        isLoading: false,
        error:
          typeof error === "object" && error !== null && "message" in error
            ? String((error as { message: unknown }).message)
            : "Could not load stock movements.",
      });
    }
  },

  reset() {
    set({
      movements: [],
      isLoading: false,
      error: null,
      hasMore: false,
      page: 1,
      total: 0,
    });
  },

  recordMovement(movement) {
    set((state) => ({ movements: [movement, ...state.movements] }));
  },

  applyProductUpdate(payload) {
    const updated = toProduct(payload);
    useCatalogueStore.setState((state) => ({
      products: state.products.map((product) =>
        product.id === updated.id ? updated : product,
      ),
    }));
  },
}));

/** Selects the movement list; stable reference until an adjustment. */
export const selectMovements = (state: InventoryStore): StockMovement[] =>
  state.movements;