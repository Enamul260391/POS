import { create } from "zustand";

import {
  createBusiness as createBusinessRequest,
  deleteBusiness as deleteBusinessRequest,
  listBusinesses,
  type BusinessFilters,
  provisionFirstAdmin as provisionFirstAdminRequest,
  updateBusiness as updateBusinessRequest,
  updateBusinessStatus as updateBusinessStatusRequest,
} from "@/features/platform/services/platformBusinessService";
import type { ApiPagination } from "@/types/api";
import type { Business, BusinessAdminFormValues, BusinessAdminResponse } from "@/types/platform";

/** Page size the directory asks for; matches `BusinessService::DEFAULT_PER_PAGE`. */
const PER_PAGE = 20;

/** Guards against a stale read overwriting a newer one. */
let loadRun = 0;

export interface PlatformBusinessStore {
  /** Rows the screen is currently showing (one server page). */
  businesses: Business[];
  /** Pagination metadata of the loaded page, as reported by `GET /platform/businesses`. */
  pagination: ApiPagination | null;
  /** `true` while a list request is in flight. */
  isLoading: boolean;
  /** Message of the last failure loading the business list, or `null`. */
  error: string | null;
  /** `true` once at least one successful business load has completed. */
  isLoaded: boolean;

  /** Loads one page of businesses into `businesses`. */
  load: (filters?: BusinessFilters, page?: number) => Promise<void>;
  /** Creates a business through the API and prepends it. */
  addBusiness: (values: import("@/types/platform").BusinessFormValues) => Promise<Business>;
  /** Updates a business through the API and replaces the cached row. */
  updateBusiness: (businessId: number, values: import("@/types/platform").BusinessFormValues) => Promise<Business>;
  /** Changes business status through the API and updates the cached row. */
  setBusinessStatus: (businessId: number, status: Business["status"]) => Promise<Business>;
  /** Deletes a business through the API and removes it from the cache. */
  removeBusiness: (businessId: number) => Promise<void>;
  /** Provisions the first Business Admin for a business. */
  provisionFirstAdmin: (businessId: number, values: BusinessAdminFormValues) => Promise<BusinessAdminResponse>;
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

export const usePlatformBusinessStore = create<PlatformBusinessStore>()((set, get) => ({
  businesses: [],
  pagination: null,
  isLoading: false,
  error: null,
  isLoaded: false,

  async load(filters, page = 1) {
    const run = ++loadRun;
    set({ isLoading: true, error: null });
    try {
      const result = await listBusinesses(
        {
          ...filters,
          perPage: filters?.perPage ?? PER_PAGE,
        },
        page,
      );
      if (run !== loadRun) {
        return;
      }

      // A page past the end (businesses removed elsewhere, or a filter that shrank)
      // comes back empty. Laravel still reports the real `last_page`, so the
      // last page that exists is re-requested once instead of leaving the
      // screen permanently blank.
      if (
        result.items.length === 0 &&
        result.pagination.total > 0 &&
        page > result.pagination.last_page
      ) {
        await get().load(filters, result.pagination.last_page);
        return;
      }

      set({
        businesses: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      if (run !== loadRun) {
        return;
      }
      set({
        isLoading: false,
        error: messageOf(error, "Could not load businesses."),
      });
    }
  },

  async addBusiness(values) {
    const created = await createBusinessRequest(values);
    set((state) => ({ businesses: [created, ...state.businesses] }));
    return created;
  },

  async updateBusiness(businessId, values) {
    const updated = await updateBusinessRequest(businessId, values);
    set((state) => ({
      businesses: state.businesses.map((business) => (business.id === businessId ? updated : business)),
    }));
    return updated;
  },

  async setBusinessStatus(businessId, status) {
    const updated = await updateBusinessStatusRequest(businessId, status);
    set((state) => ({
      businesses: state.businesses.map((business) => (business.id === businessId ? updated : business)),
    }));
    return updated;
  },

  async removeBusiness(businessId) {
    await deleteBusinessRequest(businessId);
    set((state) => ({
      businesses: state.businesses.filter((business) => business.id !== businessId),
    }));
  },

  async provisionFirstAdmin(businessId, values) {
    const result = await provisionFirstAdminRequest(businessId, values);
    return result;
  },

  reset() {
    set({
      businesses: [],
      pagination: null,
      isLoading: false,
      error: null,
      isLoaded: false,
    });
  },
}));

/** Selects the loaded directory; stable reference until a mutation. */
export const selectPlatformBusinesses = (state: PlatformBusinessStore): Business[] => state.businesses;

/** Selects the API's pagination block for the loaded page; `null` before the first successful load. */
export const selectPlatformBusinessPagination = (state: PlatformBusinessStore): ApiPagination | null =>
  state.pagination;

/** Selects the loading state. */
export const selectPlatformBusinessLoading = (state: PlatformBusinessStore): boolean => state.isLoading;

/** Selects the error state. */
export const selectPlatformBusinessError = (state: PlatformBusinessStore): string | null => state.error;

/** Selects the loaded state. */
export const selectPlatformBusinessLoaded = (state: PlatformBusinessStore): boolean => state.isLoaded;

/** Selects a single business by id. */
export const selectPlatformBusinessById = (businessId: number) => (state: PlatformBusinessStore): Business | undefined =>
  state.businesses.find((business) => business.id === businessId);