import { create } from "zustand";

import type { SupplierFormValues } from "@/features/suppliers/lib/supplierSchema";
import {
  createSupplier as createSupplierRequest,
  deleteSupplier as deleteSupplierRequest,
  listSuppliers,
  updateSupplier as updateSupplierRequest,
  type SupplierFilters,
} from "@/features/suppliers/services/supplierService";
import type { Supplier } from "@/types/supplier";

/**
 * Supplier directory (Phase 9, Phase 25).
 *
 * Why global state: two distant screens share this directory — `/suppliers`
 * manages it and `/purchases` selects from it to raise an order — and local
 * component state could not keep the two in agreement.
 *
 * Phase 25 removed the mock seed and the hand-rolled writers. What is cached is
 * deliberately small:
 *
 *   - `suppliers`     the page the management screen is currently showing
 *   - `pickerMatches` search results for the purchase form, replaced per search
 *
 * A search never appends to a growing global list, so the store cannot become
 * "every supplier in the database" — the purchase picker always asks the API and
 * replaces its result set.
 *
 * `openingBalance` is never written from the form: it is a stored supplier
 * figure the API owns, exactly like `Customer.openingBalance`.
 */
export interface SupplierStore {
  /** Rows the management screen is currently showing. */
  suppliers: Supplier[];
  /** Picker results for the last search; empty before the first one. */
  pickerMatches: Supplier[];
  /** `true` while a list or search request is in flight. */
  isLoading: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;
  /** `true` once at least one successful load has completed. */
  isLoaded: boolean;
  /** Total supplier count reported by the last `load`, for the list footer. */
  total: number;

  /** Loads one page into `suppliers`. */
  load: (filters?: SupplierFilters, page?: number) => Promise<void>;
  /** Refreshes the purchase-picker results for a search term. */
  search: (query: string) => Promise<void>;
  /** Creates a supplier through the API and prepends it. */
  createSupplier: (values: SupplierFormValues) => Promise<Supplier>;
  /** Updates a supplier through the API and replaces the cached row. */
  updateSupplier: (supplierId: number, values: SupplierFormValues) => Promise<Supplier>;
  /** Deletes a supplier through the API and drops it from the cache. */
  deleteSupplier: (supplierId: number) => Promise<void>;
  /** Empties both caches, e.g. on sign-out. */
  reset: () => void;
}

/** Maps form values onto the API body. */
function toSupplierInput(values: SupplierFormValues) {
  return {
    name: values.name.trim(),
    phone: values.phone.trim() || null,
    email: values.email.trim() || null,
    address: values.address.trim() || null,
  };
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

export const useSupplierStore = create<SupplierStore>()((set) => ({
  suppliers: [],
  pickerMatches: [],
  isLoading: false,
  error: null,
  isLoaded: false,
  total: 0,

  async load(filters, page = 1) {
    set({ isLoading: true, error: null });
    try {
      const result = await listSuppliers({
        ...filters,
        page,
        perPage: filters?.perPage ?? 20,
      });
      set({
        suppliers: result.items,
        total: result.pagination.total,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load suppliers."),
      });
    }
  },

  async search(query) {
    try {
      // Only active suppliers can be raised against on a purchase, and the API
      // enforces that too, so the filter is sent rather than applied in memory.
      const result = await listSuppliers({
        search: query.trim(),
        status: "active",
        perPage: 20,
        page: 1,
      });
      // Replace, never append: the result set always describes the last search.
      set({ pickerMatches: result.items });
    } catch (error) {
      set({ pickerMatches: [], error: messageOf(error, "Supplier search failed.") });
    }
  },

  async createSupplier(values) {
    const created = await createSupplierRequest(toSupplierInput(values));
    set((state) => ({
      suppliers: [created, ...state.suppliers],
      total: state.total + 1,
    }));
    return created;
  },

  async updateSupplier(supplierId, values) {
    const updated = await updateSupplierRequest(supplierId, toSupplierInput(values));
    // Replace rather than merge: the response is the authoritative row.
    const replace = (list: Supplier[]) =>
      list.map((supplier) => (supplier.id === supplierId ? updated : supplier));
    set((state) => ({
      suppliers: replace(state.suppliers),
      pickerMatches: replace(state.pickerMatches),
    }));
    return updated;
  },

  async deleteSupplier(supplierId) {
    await deleteSupplierRequest(supplierId);
    const drop = (list: Supplier[]) => list.filter((supplier) => supplier.id !== supplierId);
    set((state) => ({
      suppliers: drop(state.suppliers),
      pickerMatches: drop(state.pickerMatches),
      total: Math.max(0, state.total - 1),
    }));
  },

  reset() {
    set({
      suppliers: [],
      pickerMatches: [],
      isLoading: false,
      error: null,
      isLoaded: false,
      total: 0,
    });
  },
}));

/** Selects the loaded directory; stable reference until a mutation. */
export const selectSuppliers = (state: SupplierStore): Supplier[] => state.suppliers;

/** Selects the purchase-picker results; stable until the next search. */
export const selectSupplierMatches = (state: SupplierStore): Supplier[] =>
  state.pickerMatches;

