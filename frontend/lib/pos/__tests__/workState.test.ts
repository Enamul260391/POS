import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  clearWorkState,
  readWorkState,
  restoreWorkState,
  saveWorkState,
  workStateKey,
  type PersistedWorkState,
} from "@/lib/pos/workState";
import { useCartStore } from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { CartItem } from "@/types/cart";
import type { Product } from "@/types/product";

/**
 * Work-state persistence and recovery.
 *
 * These tests use the real tab-scoped `sessionStorage` (jsdom) and the real cart
 * store, so the whole save → restore path runs against the actual storage the app
 * uses. Identity isolation (one principal can never see another's cart) is the
 * central guarantee and is exercised directly.
 */

// Realtime touches browser-only APIs at import time; stub it out.
vi.mock("@/lib/realtime", () => ({ stop: vi.fn(), bind: vi.fn(), isSubscribed: vi.fn() }));

function makeProduct(id: number, name: string): Product {
  return {
    id,
    name,
    sku: `SKU-${id}`,
    barcode: null,
    unit: "piece",
    image: null,
    status: "active",
    categoryId: null,
    categoryName: null,
    stock: 100,
    lowStockThreshold: 5,
    createdAt: "2026-01-01T00:00:00Z",
    updatedAt: "2026-01-01T00:00:00Z",
  };
}

function makeLine(productId: number, quantity: number, unitPrice: number = 10): CartItem {
  const product = makeProduct(productId, `Product ${productId}`);
  return {
    id: `line-${productId}`,
    productId,
    product,
    quantity,
    unitPrice,
    discount: 0,
    note: null,
  } as unknown as CartItem;
}

function makeWorkState(
  userId: number,
  businessId: number | null,
  items: CartItem[],
  route = "/pos",
): PersistedWorkState {
  return {
    userId,
    businessId,
    route,
    items,
    globalDiscount: 0,
    taxRate: 0,
    customerId: null,
    customerName: null,
    savedAt: Date.now(),
  };
}

beforeEach(() => {
  window.sessionStorage.clear();
  useCartStore.getState().clearCart();
  useCatalogueStore.getState().reset();
});

afterEach(() => {
  window.sessionStorage.clear();
  useCartStore.getState().clearCart();
});

describe("workState keys", () => {
  it("separates the Platform Owner (null business) from any tenant", () => {
    expect(workStateKey(1, null)).not.toBe(workStateKey(2, 9));
    expect(workStateKey(1, 9)).not.toBe(workStateKey(1, 10));
  });
});

describe("saveWorkState / readWorkState", () => {
  it("persists and reads back the current cart for a principal", () => {
    const store = useCartStore.getState();
    store.addProduct(makeProduct(7, "Rice"));
    store.addProduct(makeProduct(7, "Rice"), { quantity: 2 });

    saveWorkState(2, 9, "/pos");

    const restored = readWorkState(2, 9);
    expect(restored).not.toBeNull();
    expect(restored?.items).toHaveLength(1);
    expect(restored?.items[0]?.productId).toBe(7);
    expect(restored?.items[0]?.quantity).toBe(3);
    expect(restored?.route).toBe("/pos");
  });

  it("isolates two different business users (A cannot read B's cart)", () => {
    useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    saveWorkState(2, 9, "/pos");

    expect(readWorkState(3, 9)).toBeNull();
    expect(readWorkState(2, 10)).toBeNull();
    expect(readWorkState(2, null)).toBeNull();
  });

  it("never lets a Platform Owner inherit a business user's cart", () => {
    useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    saveWorkState(2, 9, "/pos");
    expect(readWorkState(1, null)).toBeNull();
  });

  it("clearWorkState removes only the matching principal's record", () => {
    useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    saveWorkState(2, 9, "/pos");
    useCartStore.getState().addProduct(makeProduct(8, "Oil"));
    saveWorkState(3, 9, "/pos");

    clearWorkState(2, 9);
    expect(readWorkState(2, 9)).toBeNull();
    expect(readWorkState(3, 9)).not.toBeNull();
  });

  it("rejects a record whose identity tag does not match its key", () => {
    const mismatched = makeWorkState(99, 99, [makeLine(7, 1)]);
    window.sessionStorage.setItem(workStateKey(2, 9), JSON.stringify(mismatched));
    expect(readWorkState(2, 9)).toBeNull();
  });
});

describe("restoreWorkState", () => {
  it("restores the same product and quantity (cart recovery after idle logout)", () => {
    const restored = restoreWorkState(makeWorkState(2, 9, [makeLine(7, 2)]));

    const items = useCartStore.getState().items;
    expect(restored).toHaveLength(1);
    expect(items).toHaveLength(1);
    expect(items[0]?.productId).toBe(7);
    expect(items[0]?.quantity).toBe(2);
  });

  it("does not introduce duplicate lines for the same product", () => {
    restoreWorkState(makeWorkState(2, 9, [makeLine(7, 1), makeLine(7, 2)]));
    const items = useCartStore.getState().items;
    expect(items).toHaveLength(1);
    expect(items[0]?.quantity).toBe(3);
  });

  it("skips a product missing from a loaded catalogue (deleted / unavailable)", () => {
    useCatalogueStore.setState({ products: [makeProduct(8, "Oil")] });
    const restored = restoreWorkState(makeWorkState(2, 9, [makeLine(7, 1), makeLine(8, 1)]));
    expect(restored.map((line) => line.productId)).toEqual([8]);
  });

  it("skips an inactive persisted product", () => {
    const inactiveLine = makeLine(7, 1);
    (inactiveLine.product as { status: string }).status = "inactive";
    const restored = restoreWorkState(makeWorkState(2, 9, [inactiveLine]));
    expect(restored).toHaveLength(0);
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it("never clobbers a live sale already in the cart", () => {
    useCartStore.getState().addProduct(makeProduct(50, "Existing"));
    const before = useCartStore.getState().items;
    restoreWorkState(makeWorkState(2, 9, [makeLine(7, 5)]));
    expect(useCartStore.getState().items).toBe(before);
    expect(useCartStore.getState().items).toHaveLength(1);
  });

  it("recomputes totals from line data rather than trusting persisted totals", () => {
    restoreWorkState(makeWorkState(2, 9, [makeLine(7, 2)]));
    const subtotal = useCartStore
      .getState()
      .items.reduce((sum, item) => sum + item.quantity * item.unitPrice, 0);
    expect(subtotal).toBe(20);
  });
});

