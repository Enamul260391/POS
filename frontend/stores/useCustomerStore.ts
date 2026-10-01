import { create } from "zustand";

import type { CustomerFormValues } from "@/features/customers/lib/customerSchema";
import {
  createCustomer as createCustomerRequest,
  deleteCustomer as deleteCustomerRequest,
  listCustomers,
  searchCustomers as searchCustomersRequest,
  updateCustomer as updateCustomerRequest,
  type CustomerFilters,
} from "@/features/customers/services/customerService";
import type { Customer } from "@/types/customer";

/**
 * Customer directory (Phase 8, Phase 25).
 *
 * Why global state: two distant screens share this directory — `/customers`
 * manages it and the POS picker searches it — and local component state could
 * not keep the two in agreement.
 *
 * Phase 25: the mock seed is gone. What is cached is deliberately small:
 *
 *   - `search(query)`  the picker results, replaced on every keystroke
 *   - `directory`      only what the management screen has loaded
 *
 * A search never appends to a growing global list, so the store cannot become
 * "every customer in the database" — the POS picker always asks the API and
 * replaces its result set.
 *
 * Financial fields are **not** cached from the client: the API owns them.
 */
export interface CustomerStore {
  /** Rows the management screen is currently showing. */
  customers: Customer[];
  /** Picker results for the last search; empty before the first one. */
  matches: Customer[];
  /** `true` while a list or search request is in flight. */
  isLoading: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;
  /** `true` once at least one successful load has completed. */
  isLoaded: boolean;

  /** Loads one page into `customers`. */
  load: (filters?: CustomerFilters, page?: number) => Promise<void>;
  /** Refreshes the picker results for a search term. */
  search: (query: string) => Promise<void>;
  /** Creates a customer through the API and prepends it. */
  createCustomer: (values: CustomerFormValues) => Promise<Customer>;
  /** Updates a customer through the API and replaces the cached row. */
  updateCustomer: (customerId: number, values: CustomerFormValues) => Promise<Customer>;
  /** Deletes a customer through the API and drops it from the cache. */
  deleteCustomer: (customerId: number) => Promise<void>;
  /** Empties both caches, e.g. on sign-out. */
  reset: () => void;
}

/** Maps form values onto the API body. */
function toCustomerInput(values: CustomerFormValues) {
  return {
    name: values.name.trim(),
    phone: values.phone.trim() || null,
    email: values.email.trim() || null,
    address: values.address.trim() || null,
    status: values.status,
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

export const useCustomerStore = create<CustomerStore>()((set) => ({
  customers: [],
  matches: [],
  isLoading: false,
  error: null,
  isLoaded: false,

  async load(filters, page = 1) {
    set({ isLoading: true, error: null });
    try {
      const result = await listCustomers({ ...filters, page, perPage: filters?.perPage ?? 20 });
      set({
        customers: result.items,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load customers."),
      });
    }
  },

  async search(query) {
    try {
      const matches = await searchCustomersRequest(query);
      // Replace, never append: the result set always describes the last search.
      set({ matches });
    } catch (error) {
      set({ matches: [], error: messageOf(error, "Customer search failed.") });
    }
  },

  async createCustomer(values) {
    const created = await createCustomerRequest(toCustomerInput(values));
    set((state) => ({
      customers: [created, ...state.customers],
      matches: [created, ...state.matches],
    }));
    return created;
  },

  async updateCustomer(customerId, values) {
    const updated = await updateCustomerRequest(customerId, toCustomerInput(values));
    const replace = (list: Customer[]) =>
      list.map((customer) => (customer.id === customerId ? updated : customer));
    set((state) => ({
      customers: replace(state.customers),
      matches: replace(state.matches),
    }));
    return updated;
  },

  async deleteCustomer(customerId) {
    await deleteCustomerRequest(customerId);
    const drop = (list: Customer[]) => list.filter((customer) => customer.id !== customerId);
    set((state) => ({
      customers: drop(state.customers),
      matches: drop(state.matches),
    }));
  },

  reset() {
    set({ customers: [], matches: [], isLoading: false, error: null, isLoaded: false });
  },
}));

/** Selects the loaded directory; stable reference until a mutation. */
export const selectCustomers = (state: CustomerStore): Customer[] => state.customers;

/** Selects the picker results; stable reference until the next search. */
export const selectCustomerMatches = (state: CustomerStore): Customer[] =>
  state.matches;