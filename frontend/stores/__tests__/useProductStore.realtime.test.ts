import { beforeEach, describe, expect, it, vi } from "vitest";

import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";

/**
 * The catalogue store's realtime stock handling.
 *
 * The store is the one place that decides what a realtime frame means. Two
 * readers depend on it:
 *
 *   - the cached catalogue row (`products[].stock`), used by the Inventory and
 *     Products screens and by the POS cart line;
 *   - the `liveStock` map, used by the POS terminal, whose product rows come
 *     from its own server-side search and are therefore not the cached rows.
 *
 * The frames are validated before they are applied: a frame for another
 * tenant, or one without a balance, must change nothing at all.
 */

const { boundHandlers, subscribeToBusiness } = vi.hoisted(() => ({
  /** The handlers the store bound, keyed by realtime event name. */
  boundHandlers: new Map<string, (payload: unknown) => void>(),
  subscribeToBusiness: vi.fn(() => ({})),
}));

vi.mock("@/lib/realtime", () => ({
  bind: vi.fn((event: string, handler: (payload: unknown) => void) => {
    boundHandlers.set(event, handler);
    return () => undefined;
  }),
  isSubscribed: vi.fn(() => false),
  onReconnected: vi.fn(() => () => undefined),
  subscribeToBusiness,
  REALTIME_EVENTS: {
    productCreated: "product.created",
    productUpdated: "product.updated",
    productDeleted: "product.deleted",
    productStockUpdated: "product.stock.updated",
  },
}));

const product: Product = {
  id: 46,
  name: "Wireless Mouse",
  sku: "MOUSE-001",
  barcode: "1000000000001",
  categoryId: null,
  categoryName: null,
  costPrice: 50,
  sellingPrice: 400,
  taxRate: 0,
  image: null,
  description: null,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
  unit: "piece",
  stock: 100,
  lowStockThreshold: 10,
  status: "active",
};

/** The business the frames below belong to. */
const BUSINESS_ID = 7;

/** Starts the realtime sync and returns the store's stock handler. */
function startSync(): (payload: unknown) => void {
  useCatalogueStore.getState().startRealtimeSync(BUSINESS_ID);

  const handler = boundHandlers.get("product.stock.updated");

  if (handler === undefined) {
    throw new Error("the stock event was never bound");
  }

  return handler;
}

/** A frame as the backend sends it. */
function stockFrame(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    business_id: BUSINESS_ID,
    product_id: product.id,
    current_stock: "12.5",
    ...overrides,
  };
}

beforeEach(() => {
  boundHandlers.clear();
  useCatalogueStore.getState().reset();
});

describe("useCatalogueStore — the realtime stock event", () => {
  it("updates the cached product and records the pushed balance", () => {
    useCatalogueStore.getState().cacheProducts([product]);
    const handler = startSync();

    handler(stockFrame());

    const state = useCatalogueStore.getState();

    // The cached row carries the authoritative balance.
    expect(state.products[0]?.stock).toBe(12.5);

    // And the balance is recorded for the screens that hold their own rows.
    expect(state.liveStock[product.id]).toBe(12.5);
  });

  it("records a balance for a product that is not cached", () => {
    const handler = startSync();

    handler(stockFrame());

    expect(useCatalogueStore.getState().liveStock[product.id]).toBe(12.5);
    expect(useCatalogueStore.getState().products).toHaveLength(0);
  });

  it("ignores a frame from another tenant", () => {
    useCatalogueStore.getState().cacheProducts([product]);
    const handler = startSync();

    handler(stockFrame({ business_id: BUSINESS_ID + 1, current_stock: "3" }));

    const state = useCatalogueStore.getState();

    expect(state.products[0]?.stock).toBe(100);
    expect(state.liveStock[product.id]).toBeUndefined();
  });

  it("ignores a frame without a balance", () => {
    useCatalogueStore.getState().cacheProducts([product]);
    const handler = startSync();

    handler(stockFrame({ current_stock: undefined }));

    const state = useCatalogueStore.getState();

    expect(state.products[0]?.stock).toBe(100);
    expect(state.liveStock[product.id]).toBeUndefined();
  });

  it("keeps only the latest balance per product", () => {
    const handler = startSync();

    handler(stockFrame({ current_stock: "12.5" }));
    handler(stockFrame({ current_stock: "4" }));

    expect(useCatalogueStore.getState().liveStock[product.id]).toBe(4);
  });

  it("drops the balance when the product is deleted", () => {
    const handler = startSync();
    handler(stockFrame());

    expect(useCatalogueStore.getState().liveStock[product.id]).toBe(12.5);

    boundHandlers.get("product.deleted")?.({ business_id: BUSINESS_ID, product_id: product.id });

    expect(useCatalogueStore.getState().liveStock[product.id]).toBeUndefined();
  });

  it("clears the balances when the catalogue is reset", () => {
    const handler = startSync();
    handler(stockFrame());

    expect(useCatalogueStore.getState().liveStock[product.id]).toBe(12.5);

    useCatalogueStore.getState().reset();

    expect(useCatalogueStore.getState().liveStock).toEqual({});
  });
});
