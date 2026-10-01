import { create } from "zustand";

import {
  closeRegister as closeRegisterRequest,
  getCurrentRegister,
  getRegisterSummary,
  listTransactions,
  openRegister as openRegisterRequest,
  recordManualTransaction,
  type CloseRegisterInput,
  type ManualTransactionInput,
  type OpenRegisterInput,
} from "@/features/cashDrawer/services/cashDrawerService";
import { messageOfApiError } from "@/lib/api/validation";
import type { ApiPagination } from "@/types/api";
import type {
  CashRegister,
  CashRegisterSummary,
  CashRegisterTransaction,
} from "@/types/cashDrawer";

/**
 * Cash drawer store.
 *
 * The store is a **cache of API responses**, not a second source of truth. The
 * backend owns the register: `mock/cashDrawer.json` is gone, nothing here
 * creates ids, invents balances or derives an expected-cash figure from a local
 * transaction array.
 *
 * What is cached, and where each value comes from:
 *
 *   `register`      `GET /cash-registers/current`, which already carries
 *                   `current_balance` and `transaction_summary` — the API's own
 *                   aggregates. `null` means the drawer is closed, which the API
 *                   reports as a `404` and the service translates.
 *   `summary`       `GET /cash-registers/{id}/summary`. The one thing the
 *                   register resource cannot give us: `transaction_count`.
 *   `transactions`  `GET /cash-registers/{id}/transactions` (one page, newest
 *                   first). These include the cash-sale rows the sales and
 *                   payment flows posted automatically.
 *
 * After every mutation the store re-reads from the API instead of patching
 * locally, so a screen can never show a balance the server has not confirmed.
 * A `runId` guard discards a superseded read, and a `pending` flag serialises
 * mutations so a double click cannot open, close or adjust twice.
 */
export interface CashDrawerStore {
  /** The authenticated user's open register, or `null` when the drawer is closed. */
  register: CashRegister | null;
  /** Server-side aggregate of the open register, `null` when there is none. */
  summary: CashRegisterSummary | null;
  /** Newest page of the register's transactions. */
  transactions: CashRegisterTransaction[];
  /** Pagination of the last transaction request. */
  pagination: ApiPagination | null;
  /** `true` while a read is in flight. */
  isLoading: boolean;
  /** `true` once at least one successful read has completed. */
  isLoaded: boolean;
  /** `true` while an open / close / adjust request is in flight. */
  isPending: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;

  /** Re-reads the current register, its summary and its transactions. */
  load: () => Promise<void>;
  /** Opens a register and re-reads everything. */
  openRegister: (input: OpenRegisterInput) => Promise<CashRegister>;
  /** Closes the register and re-reads everything. */
  closeRegister: (input: CloseRegisterInput) => Promise<CashRegister>;
  /** Records a manual cash in / cash out and re-reads everything. */
  recordCashMove: (input: ManualTransactionInput) => Promise<void>;
  /** Empties the cache, e.g. on sign-out. */
  reset: () => void;
}

/** How many transactions one read asks for (the API's per-page maximum). */
const TRANSACTIONS_PER_PAGE = 100;

/** Guards against a stale read overwriting a newer one. */
let loadRun = 0;

export const useCashDrawerStore = create<CashDrawerStore>()((set, get) => ({
  register: null,
  summary: null,
  transactions: [],
  pagination: null,
  isLoading: false,
  isLoaded: false,
  isPending: false,
  error: null,

  async load() {
    const run = ++loadRun;
    set({ isLoading: true, error: null });

    try {
      const register = await getCurrentRegister();
      if (run !== loadRun) {
        return;
      }

      // No open register is a valid state, not a failure, so the two dependent
      // reads are skipped entirely instead of firing guaranteed-404 requests.
      if (register === null) {
        set({
          register: null,
          summary: null,
          transactions: [],
          pagination: null,
          isLoading: false,
          isLoaded: true,
        });
        return;
      }

      // They cannot be issued before `current` answers, because they need its id.
      const [summary, page] = await Promise.all([
        getRegisterSummary(register.id),
        listTransactions(register.id, { perPage: TRANSACTIONS_PER_PAGE }),
      ]);

      if (run !== loadRun) {
        return;
      }

      set({
        register,
        summary,
        transactions: page.items,
        pagination: page.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      if (run !== loadRun) {
        return;
      }
      set({
        isLoading: false,
        error: messageOfApiError(error, "Could not load the cash drawer."),
      });
    }
  },

  async openRegister(input) {
    if (get().isPending) {
      throw new Error("A drawer action is already in progress.");
    }
    set({ isPending: true, error: null });
    try {
      const register = await openRegisterRequest(input);
      await get().load();
      return register;
    } catch (error) {
      set({ error: messageOfApiError(error, "Could not open the register.") });
      throw error;
    } finally {
      set({ isPending: false });
    }
  },

  async closeRegister(input) {
    const register = get().register;
    if (register === null) {
      throw new Error("No cash register is currently open.");
    }
    if (get().isPending) {
      throw new Error("A drawer action is already in progress.");
    }
    set({ isPending: true, error: null });
    try {
      const closed = await closeRegisterRequest(register.id, input);
      await get().load();
      return closed;
    } catch (error) {
      set({ error: messageOfApiError(error, "Could not close the register.") });
      throw error;
    } finally {
      set({ isPending: false });
    }
  },

  async recordCashMove(input) {
    const register = get().register;
    if (register === null) {
      throw new Error("No cash register is currently open.");
    }
    if (get().isPending) {
      throw new Error("A drawer action is already in progress.");
    }
    set({ isPending: true, error: null });
    try {
      await recordManualTransaction(register.id, input);
      await get().load();
    } catch (error) {
      set({ error: messageOfApiError(error, "Could not record the movement.") });
      throw error;
    } finally {
      set({ isPending: false });
    }
  },

  reset() {
    // Invalidate any read still in flight so it cannot repopulate a cleared cache.
    loadRun += 1;
    set({
      register: null,
      summary: null,
      transactions: [],
      pagination: null,
      isLoading: false,
      isLoaded: false,
      isPending: false,
      error: null,
    });
  },
}));

/** Selects the open register; stable reference until the next read. */
export const selectCashRegister = (state: CashDrawerStore): CashRegister | null =>
  state.register;

/** Selects the server-side summary; stable reference until the next read. */
export const selectCashSummary = (
  state: CashDrawerStore,
): CashRegisterSummary | null => state.summary;

/** Selects the loaded transaction page; stable reference until the next read. */
export const selectCashTransactions = (
  state: CashDrawerStore,
): CashRegisterTransaction[] => state.transactions;

/** Selects the pagination of the last transaction request. */
export const selectCashPagination = (state: CashDrawerStore): ApiPagination | null =>
  state.pagination;

