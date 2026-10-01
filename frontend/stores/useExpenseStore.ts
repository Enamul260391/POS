import { create } from "zustand";

import { roundMoney } from "@/features/expenses/lib/expenses";
import type { ExpenseFormValues } from "@/features/expenses/lib/expenseSchema";
import {
  createExpense as createExpenseRequest,
  deleteExpense as deleteExpenseRequest,
  listAllExpenseCategories,
  listExpenses,
  updateExpense as updateExpenseRequest,
  type ExpenseFilters,
  type ExpenseInput,
} from "@/features/expenses/services/expenseService";
import type { ApiPagination } from "@/types/api";
import type { Expense, ExpenseCategory } from "@/types/expense";

/**
 * Expense ledger store.
 *
 * Why global state: the `/expenses` screen owns the list, and the `/reports`
 * screen reads the same ledger to build its expense and profit cards, so the
 * two have to agree. Local component state could not keep them in step.
 *
 * The mock seed (`mock/expenses.json`) and the hand-rolled writers are gone.
 * What is cached is deliberately small:
 *
 *   - `expenses`    the page the screen is currently showing
 *   - `categories`  the closed set the form and the category filter render
 *
 * A search never appends to a growing global list, so the store can never
 * become "every expense in the database": the screen always asks the API and
 * replaces its result set.
 *
 * The backend owns the ledger rules, so the store never does business maths on
 * its own:
 *
 *   - money is rounded with the shared `roundMoney` before it is sent, and the
 *     authoritative figure is always the one the API returns;
 *   - the cash-drawer pairing is a server-side transaction. Creating a cash
 *     expense posts a register movement inside the same commit, and updating
 *     one that already moved a register is refused by the service layer â€”
 *     neither is something the client may paper over;
 *   - `created_by` and the audit timestamps come from the API, never from here.
 */

/** Pagination of the most recent list response; reset before the first load. */
const EMPTY_PAGINATION: ApiPagination = {
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
  from: null,
  to: null,
};

export interface ExpenseStore {
  /** The page of expenses the screen is currently showing. */
  expenses: Expense[];
  /** Categories available to the form and the filter. */
  categories: ExpenseCategory[];
  /** `true` while a list request is in flight. */
  isLoading: boolean;
  /** `true` while the category lookup is in flight. */
  isLoadingCategories: boolean;
  /** Display-safe message of the last list failure, or `null`. */
  error: string | null;
  /** `true` once at least one successful list load has completed. */
  isLoaded: boolean;
  /** Pagination block of the last list response. */
  pagination: ApiPagination;

  /** Loads one page into `expenses`. */
  load: (filters?: ExpenseFilters, page?: number) => Promise<void>;
  /** Loads the categories the form and the filter offer. */
  loadCategories: () => Promise<void>;
  /** Creates an expense through the API and returns the stored row. */
  createExpense: (values: ExpenseFormValues) => Promise<Expense>;
  /** Updates an expense through the API and replaces the cached row. */
  updateExpense: (
    expenseId: number,
    values: ExpenseFormValues,
  ) => Promise<Expense>;
  /** Deletes an expense through the API and drops it from the cache. */
  deleteExpense: (expenseId: number) => Promise<void>;
  /** Empties the cache, e.g. on sign-out. */
  reset: () => void;
}

/**
 * Maps form values onto the service input.
 *
 * Blank optional text becomes `null` rather than `""`, because the API stores
 * "no value" as `null` and an empty string would round-trip as a meaningless
 * reference. The amount is rounded with the shared helper so the wire value
 * matches the API's `decimal:0,2` rule.
 */
function toExpenseInput(values: ExpenseFormValues): ExpenseInput {
  return {
    categoryId: values.categoryId,
    amount: roundMoney(values.amount),
    expenseDate: values.expenseDate,
    paymentMethod: values.paymentMethod,
    reference: values.reference.trim() || null,
    description: values.description.trim() || null,
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

export const useExpenseStore = create<ExpenseStore>()((set) => ({
  expenses: [],
  categories: [],
  isLoading: false,
  isLoadingCategories: false,
  error: null,
  isLoaded: false,
  pagination: EMPTY_PAGINATION,

  async load(filters, page = 1) {
    set({ isLoading: true, error: null });
    try {
      const result = await listExpenses({
        ...filters,
        page,
        perPage: filters?.perPage ?? 20,
      });
      set({
        expenses: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load expenses."),
      });
    }
  },

  async loadCategories() {
    set({ isLoadingCategories: true });
    try {
      const categories = await listAllExpenseCategories();
      set({ categories, isLoadingCategories: false });
    } catch (error) {
      // The ledger itself is still browsable without categories, so this only
      // surfaces when the lookup fails outright.
      set({
        isLoadingCategories: false,
        error: messageOf(error, "Could not load expense categories."),
      });
    }
  },

  async createExpense(values) {
    // The response is the authoritative row â€” server-assigned id, `created_by`,
    // audit timestamps and the cash-register pairing â€” so nothing is merged
    // into a locally invented object.
    const created = await createExpenseRequest(toExpenseInput(values));
    set((state) => ({
      expenses: [created, ...state.expenses],
      pagination: {
        ...state.pagination,
        total: state.pagination.total + 1,
      },
    }));
    return created;
  },

  async updateExpense(expenseId, values) {
    const updated = await updateExpenseRequest(
      expenseId,
      toExpenseInput(values),
    );
    // Replace rather than merge: the response is the stored row.
    set((state) => ({
      expenses: state.expenses.map((expense) =>
        expense.id === expenseId ? updated : expense,
      ),
    }));
    return updated;
  },

  async deleteExpense(expenseId) {
    await deleteExpenseRequest(expenseId);
    const drop = (list: Expense[]) =>
      list.filter((expense) => expense.id !== expenseId);
    set((state) => ({
      expenses: drop(state.expenses),
      pagination: {
        ...state.pagination,
        total: Math.max(0, state.pagination.total - 1),
      },
    }));
  },

  reset() {
    set({
      expenses: [],
      categories: [],
      isLoading: false,
      isLoadingCategories: false,
      error: null,
      isLoaded: false,
      pagination: EMPTY_PAGINATION,
    });
  },
}));

/** Selects the loaded ledger page; stable reference until a mutation. */
export const selectExpenses = (state: ExpenseStore): Expense[] =>
  state.expenses;

/** Selects the categories the form and the filter render. */
export const selectExpenseCategories = (
  state: ExpenseStore,
): ExpenseCategory[] => state.categories;
