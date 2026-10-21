import { create } from "zustand";

import { listAllCategories } from "@/features/categories/services/categoryService";
import type { ProductFormValues } from "@/features/products/lib/productSchema";
import {
  createProduct as createProductRequest,
  deleteProduct as deleteProductRequest,
  getProduct,
  listAllProducts,
  updateProduct as updateProductRequest,
  type ProductFilters,
} from "@/features/products/services/productService";
import { toQuantity } from "@/lib/api/values";
import {
  bind,
  isSubscribed,
  onReconnected,
  REALTIME_EVENTS,
  subscribeToBusiness,
} from "@/lib/realtime";
import type { Category } from "@/types/category";
import type { Product } from "@/types/product";

/**
 * Product + category catalogue (Phase 7, Phase 25).
 *
 * The single place the catalogue is held, because three distant screens need it
 * at once and re-fetching per screen would be wasteful and inconsistent:
 *
 *   `/products`    manages it (add / edit / delete)
 *   `/inventory`   reads stock levels
 *   `/pos`         sells from it and must stay fast
 *
 * Phase 25 replaced the mock seeds with a hydration from the API. The store is a
 * **cache of what has been loaded**, not a second source of truth: `hydrate`
 * fetches, and every mutation goes through the API and then updates the cached
 * copy with the server's own response (including the recalculated ledger stock),
 * so the frontend never computes stock.
 *
 * `business_id` is never sent: Laravel derives the tenant from the bearer token.
 */

/** Filters used by `hydrate`. */
export interface CatalogueLoadOptions {
  /** Extra server-side filters, e.g. `{ status: "active" }` for the POS. */
  products?: Omit<ProductFilters, "page" | "perPage">;
}

export interface CatalogueStore {
  /** Loaded products; `[]` until the first `hydrate` resolves. */
  products: Product[];
  /** Loaded categories; `[]` until the first `hydrate` resolves. */
  categories: Category[];
  /** `true` while `hydrate` is in flight. */
  isLoading: boolean;
  /** Display-safe message of the last **product** failure, or `null`. */
  error: string | null;
  /**
   * Display-safe message of the last **category** failure, or `null`.
   *
   * Separate from `error` on purpose: the two requests are independent, so a
   * broken `/categories` must not blank a perfectly good product table, and
   * must not be reported as if the products failed to load. `categories` keeps
   * whatever was last loaded, so only the category filter and the form's select
   * are degraded.
   */
  categoryError: string | null;
  /** `true` once at least one successful product load has completed. */
  isLoaded: boolean;

  /** Loads the catalogue from the API, replacing whatever is cached. */
  hydrate: (options?: CatalogueLoadOptions) => Promise<void>;
  /** Re-runs `hydrate` with the last options. */
  reload: () => Promise<void>;
  /** Creates a product through the API and caches the response. */
  createProduct: (values: ProductFormValues) => Promise<Product>;
  /** Updates a product through the API and replaces the cached row. */
  updateProduct: (productId: number, values: ProductFormValues) => Promise<Product>;
  /** Deletes a product through the API and drops it from the cache. */
  deleteProduct: (productId: number) => Promise<void>;
  /** Upserts one category into the cache after an API write. */
  cacheCategory: (category: Category) => void;
  /**
   * Upserts rows the POS resolved one at a time (a search result page, a scanned
   * barcode) so a cart line can still find its product's master data.
   *
   * The POS does not hold the catalogue, so a scanned product would otherwise be
   * absent from the cache and its cart line could not show remaining stock.
   * This is a **cache** write only: it never sets `isLoaded`, never clears rows
   * another screen loaded, and never triggers a request — so `/products`,
   * `/inventory` and `/purchases` still perform their own full `hydrate`.
   */
  cacheProducts: (products: Product[]) => void;
  /**
   * Clears every trace of the cached catalogue.
   *
   * Called on logout and on a `401`. Without it the next account signing in on
   * the same tab would briefly see the previous one's products, because the
   * store outlives the session. Realtime teardown is handled separately by
   * `@/lib/realtime`; this is only about the cached data.
   */
  reset: () => void;
  /**
   * Subscribes to `businessId`'s realtime channel and keeps the cache in sync
   * until the returned function is called.
   *
   * `businessId` is passed in by the caller from the authenticated user, so this
   * store never has to reach into auth state or guess a tenant. Safe to call
   * unconditionally: with no Reverb configuration it resolves to a no-op.
   * Must be called from an effect whose cleanup uses the returned function, so a
   * remount cannot accumulate listeners.
   */
  startRealtimeSync: (businessId: number) => () => void;
}

/** The options the last `hydrate` used, so `reload` can repeat them. */
let lastLoadOptions: CatalogueLoadOptions = {};

/**
 * Monotonic id of the most recent `hydrate` call.
 *
 * A module-level counter rather than component state, because `hydrate` is a
 * store action shared by several screens. Each run captures its own id and
 * refuses to write once a newer run has started, so a slow first request can
 * never overwrite the results of a faster retry. This mirrors the `runIdRef`
 * guard `useApiResource` applies per hook instance.
 */
let latestRunId = 0;

/** Extracts a display-safe message from anything a service rejected with. */
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

/**
 * Maps form values onto the API body.
 *
 * Blank optional strings become `null` because Laravel normalises them anyway
 * and the unique indexes treat "no SKU" as NULL, not `''`. `categoryId === 0` is
 * the select's "not chosen" sentinel, sent as a null category.
 *
 * There is no quantity in the payload, for create or update: a product is
 * master data, and stock moves only through the stock-adjustment ledger.
 */
function toProductInput(values: ProductFormValues) {
  return {
    name: values.name.trim(),
    sku: values.sku.trim() || null,
    barcode: values.barcode.trim() || null,
    categoryId: values.categoryId > 0 ? values.categoryId : null,
    costPrice: values.costPrice,
    sellingPrice: values.sellingPrice,
    lowStockThreshold: values.lowStockThreshold,
    unit: values.unit,
    status: values.status,
  };
}

/**
 * Realtime event payloads broadcast by Laravel (`broadcastWith()` on each event).
 *
 * They are validated at runtime before use: a WebSocket frame is untrusted input
 * exactly like a response body, and a malformed one must be ignored rather than
 * crash a render.
 */
interface ProductEventPayload {
  product_id: number;
  business_id: number;
}

interface StockEventPayload extends ProductEventPayload {
  current_stock: string;
}

/** `true` when the value is a finite, positive integer id. */
function isProductId(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value > 0;
}

/**
 * Narrows a realtime payload to a product event.
 *
 * Returns `null` for anything unexpected, so a bad frame is dropped instead of
 * corrupting the cache. `businessId` is echoed back so the store can verify the
 * frame belongs to the signed-in tenant — a defence in depth on top of the
 * backend's channel authorization, not a replacement for it.
 */
function toProductEvent(
  payload: unknown,
  businessId: number,
): ProductEventPayload | null {
  if (typeof payload !== "object" || payload === null) {
    return null;
  }

  const candidate = payload as Partial<ProductEventPayload>;

  if (!isProductId(candidate.product_id) || candidate.business_id !== businessId) {
    return null;
  }

  return { product_id: candidate.product_id, business_id: businessId };
}

/** Narrows a stock frame, requiring the authoritative balance the API sent. */
function toStockEvent(
  payload: unknown,
  businessId: number,
): StockEventPayload | null {
  const base = toProductEvent(payload, businessId);

  if (base === null) {
    return null;
  }

  const currentStock = (payload as Partial<StockEventPayload>).current_stock;

  if (typeof currentStock !== "string" || currentStock.trim() === "") {
    return null;
  }

  return { ...base, current_stock: currentStock };
}

/**
 * Whether a product belongs in the cache given how it was last loaded.
 *
 * The POS loads only `{ status: "active" }` (an inactive product must never be
 * sellable), while `/products` loads the whole catalogue. Blindly inserting a
 * fetched product would put an inactive row into the POS grid and make it
 * sellable — a realtime bug that creates a *business* bug. So a product is only
 * added when it satisfies the scope the cache was loaded with.
 */
function matchesLoadedScope(product: Product): boolean {
  const requiredStatus = lastLoadOptions.products?.status;

  return requiredStatus === undefined || product.status === requiredStatus;
}

/**
 * A store writer accepting either a partial state or an updater function.
 *
 * The realtime handlers need the updater form: an event arrives outside React,
 * so reading current state from a closure would race with a hydration that is
 * still in flight.
 */
type SetState = (
  partial:
    | Partial<CatalogueStore>
    | ((state: CatalogueStore) => Partial<CatalogueStore>),
) => void;

/**
 * Applies the authoritative stock the backend reported for one product.
 *
 * The value is the backend's own ledger balance, read from the event — the
 * frontend never derives one (`stock - quantity`). Only the stock field is
 * touched, so a product's name, price and category are left exactly as the last
 * REST response delivered them.
 */
function applyStock(set: SetState, productId: number, currentStock: string): void {
  set((state) => ({
    products: state.products.map((product) =>
      product.id === productId
        ? { ...product, stock: toQuantity(currentStock) }
        : product,
    ),
  }));
}

/**
 * Re-reads one product from the API and merges it into the cache.
 *
 * Used for both `product.created` and `product.updated`: the events carry only a
 * product id by design, so the authoritative row (name, prices, category, stock)
 * comes from `GET /products/{id}` — exactly the same mapper and service the
 * initial load uses, so there is one code path for reading a product.
 *
 * The merge is an **upsert keyed by id**, which is what makes a duplicate event
 * harmless: replaying "created" twice leaves one row, not two. A product that
 * no longer matches the loaded scope (e.g. just deactivated in the POS) is
 * removed rather than inserted.
 */
async function refreshOneProduct(set: SetState, productId: number): Promise<void> {
  try {
    const product = await getProduct(productId);
    const inScope = matchesLoadedScope(product);

    set((state) => {
      const exists = state.products.some((entry) => entry.id === productId);

      if (!inScope) {
        return {
          products: state.products.filter((entry) => entry.id !== productId),
        };
      }

      return {
        products: exists
          ? state.products.map((entry) => (entry.id === productId ? product : entry))
          : [product, ...state.products],
      };
    });
  } catch {
    // A product that was deleted, or belongs to another tenant, answers 404.
    // That is not an error to surface: a later `hydrate` reconciles the list.
    // Swallowing it here keeps a realtime frame from ever breaking a screen.
  }
}

/**
 * Drops a deleted product from the cache.
 *
 * Only the catalogue entry is removed. A product sitting in the POS cart is
 * left completely alone: the backend defines no rule for "a cart line was
 * deleted", and inventing one (auto-removing the line, blocking checkout) would
 * be a business decision rather than a realtime one. See the phase report.
 */
function removeOneProduct(set: SetState, productId: number): void {
  set((state) => ({
    products: state.products.filter((entry) => entry.id !== productId),
  }));
}

/** The tenant whose channel the current subscription belongs to. */
let subscribedBusinessId: number | null = null;

export const useCatalogueStore = create<CatalogueStore>()((set, get) => ({
  products: [],
  categories: [],
  isLoading: false,
  error: null,
  categoryError: null,
  isLoaded: false,

  async hydrate(options) {
    if (options !== undefined) {
      lastLoadOptions = options;
    }

    latestRunId += 1;
    const runId = latestRunId;

    set({ isLoading: true, error: null, categoryError: null });

    /** Writes a partial result, unless a newer `hydrate` has superseded this run. */
    const apply = (partial: Partial<CatalogueStore>): void => {
      if (runId === latestRunId) {
        set(partial);
      }
    };

    // The two requests stay concurrent — the POS should not wait for two round
    // trips — but they are settled **independently** rather than through
    // `Promise.all`. Under `Promise.all` the first rejection rejected the pair
    // and discarded the other's data, so a failing `/categories` blanked a
    // perfectly good product table and a failing `/products` discarded the
    // category list. Each side now owns its own success/failure path.
    //
    // Failures are recorded in their own error slot rather than swallowed: the
    // screen renders both and both are retryable via `hydrate`.
    const loadProducts = listAllProducts(lastLoadOptions.products)
      .then((products) => {
        apply({ products, isLoaded: true });
      })
      .catch((error: unknown) => {
        apply({
          error: messageOf(error, "Could not load products."),
          // `isLoaded` stays as it was: a failed first load must not claim the
          // catalogue is ready, so the screen keeps its loading/retry affordance.
        });
      });

    const loadCategories = listAllCategories()
      .then((categories) => {
        apply({ categories });
      })
      .catch((error: unknown) => {
        // `categories` is deliberately left untouched, so a category outage
        // degrades only the filter and the form's select, not the product table.
        apply({ categoryError: messageOf(error, "Could not load categories.") });
      });

    // `allSettled`-style: neither rejection can reject the other, so the
    // loading flag is always cleared exactly once, when both have finished.
    await Promise.all([loadProducts, loadCategories]);

    apply({ isLoading: false });
  },

  async reload() {
    await get().hydrate(lastLoadOptions);
  },

  async createProduct(values) {
    const created = await createProductRequest(toProductInput(values));
    set((state) => ({ products: [created, ...state.products] }));
    return created;
  },

  async updateProduct(productId, values) {
    const updated = await updateProductRequest(productId, toProductInput(values));
    // Replace rather than merge: the response is the authoritative row,
    // including the stock balance the ledger reports.
    set((state) => ({
      products: state.products.map((product) =>
        product.id === productId ? updated : product,
      ),
    }));
    return updated;
  },

  async deleteProduct(productId) {
    await deleteProductRequest(productId);
    set((state) => ({
      products: state.products.filter((product) => product.id !== productId),
    }));
  },

  cacheProducts(products) {
    if (products.length === 0) {
      return;
    }
    set((state) => {
      const known = new Set(state.products.map((entry) => entry.id));
      const incoming = products.filter((product) => !known.has(product.id));
      return incoming.length === 0 ? state : { products: [...incoming, ...state.products] };
    });
  },

  cacheCategory(category) {
    set((state) => {
      const exists = state.categories.some((entry) => entry.id === category.id);
      return {
        categories: exists
          ? state.categories.map((entry) =>
              entry.id === category.id ? category : entry,
            )
          : [...state.categories, category],
      };
    });
  },

  reset() {
    set({
      products: [],
      categories: [],
      isLoading: false,
      error: null,
      categoryError: null,
      isLoaded: false,
    });
  },

  startRealtimeSync(businessId) {
    // Idempotence guard. A subscription must exist for a business even if this
    // is called again for the same one: a second set of listeners would apply
    // every update twice. Effect re-runs (React Strict Mode in development,
    // route changes) therefore reuse the existing subscription rather than
    // stacking another one on top of it.
    if (isSubscribed() && subscribedBusinessId === businessId) {
      return () => undefined;
    }

    // Subscribing is best-effort: with no Reverb key, during SSR, or when the
    // socket cannot be opened, this is a no-op and the app keeps working over
    // REST alone.
    const channel = subscribeToBusiness(businessId);

    if (channel === null) {
      return () => undefined;
    }

    subscribedBusinessId = businessId;
    // Zustand's own writer, so the realtime handlers share the store's updater
    // semantics (and its subscriber notifications) instead of a hand-rolled copy.
    const set: SetState = (partial) => {
      useCatalogueStore.setState(partial);
    };

    const unbindCreated = bind(REALTIME_EVENTS.productCreated, (payload) => {
      const event = toProductEvent(payload, businessId);
      if (event !== null) {
        void refreshOneProduct(set, event.product_id);
      }
    });

    const unbindUpdated = bind(REALTIME_EVENTS.productUpdated, (payload) => {
      const event = toProductEvent(payload, businessId);
      if (event !== null) {
        void refreshOneProduct(set, event.product_id);
      }
    });

    const unbindDeleted = bind(REALTIME_EVENTS.productDeleted, (payload) => {
      const event = toProductEvent(payload, businessId);
      if (event !== null) {
        removeOneProduct(set, event.product_id);
      }
    });

    // Stock is the one event that carries a value, so it is applied directly
    // rather than triggering a fetch: a busy till produces many of these and a
    // request per movement would be wasteful. The number is the backend's.
    const unbindStock = bind(REALTIME_EVENTS.productStockUpdated, (payload) => {
      const event = toStockEvent(payload, businessId);
      if (event !== null) {
        applyStock(set, event.product_id, event.current_stock);
      }
    });

    // Reconnect is event-driven, never a timer: on a genuine reconnect we do one
    // authoritative REST reload, because frames sent while the socket was down
    // were never delivered. This is not polling — it fires once per reconnect.
    const unobserveReconnect = onReconnected(() => {
      void get().hydrate(lastLoadOptions);
    });

    return () => {
      unbindCreated();
      unbindUpdated();
      unbindDeleted();
      unbindStock();
      unobserveReconnect();
      subscribedBusinessId = null;
    };
  },
}));

/** Selects the cached products; stable reference until the cache changes. */
export const selectProducts = (state: CatalogueStore): Product[] => state.products;

/** Selects the cached categories; stable reference until the cache changes. */
export const selectCategories = (state: CatalogueStore): Category[] =>
  state.categories;
