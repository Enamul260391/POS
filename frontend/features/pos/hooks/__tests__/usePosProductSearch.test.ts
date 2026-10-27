import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { usePosProductSearch } from "@/features/pos/hooks/usePosProductSearch";
import { searchSellableProducts } from "@/features/products/services/productService";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";

/**
 * The POS search hook's realtime overlay.
 *
 * The hook owns two sources at once: the server's search result (which decides
 * which products are on screen and carries their master data) and the balances
 * the server pushes over realtime (which carry the authoritative stock). The
 * contract is that the pushed balance wins for a product the server has
 * mentioned, and that the REST balance is kept for every other product — a
 * disconnected socket must never make the grid worse than the REST answer.
 */

// The catalogue store imports the same service module, so every export it
// needs has to exist in the mock.
vi.mock("@/features/products/services/productService", () => ({
  searchSellableProducts: vi.fn(),
  listAllProducts: vi.fn().mockResolvedValue([]),
  listProducts: vi.fn().mockResolvedValue([]),
  getProduct: vi.fn(),
  findProductByCode: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
}));

/** The row the search request answered with. */
const searchRow: Product = {
  id: 46,
  name: "Wireless Mouse",
  sku: "MOUSE-001",
  barcode: "1000000000001",
  categoryId: null,
  categoryName: null,
  image: null,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
  unit: "piece",
  stock: 100,
  lowStockThreshold: 10,
  status: "active",
};

/** Renders the hook and lets the search debounce and request settle. */
async function renderSearch() {
  const view = renderHook(() => usePosProductSearch("mouse"));

  await act(async () => {
    vi.advanceTimersByTime(300);
  });

  return view;
}

beforeEach(() => {
  vi.useFakeTimers();
  useCatalogueStore.getState().reset();
  vi.mocked(searchSellableProducts).mockReset();
  vi.mocked(searchSellableProducts).mockResolvedValue([searchRow]);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("usePosProductSearch — the realtime stock overlay", () => {
  it("prefers the pushed balance over the stock the search returned", async () => {
    act(() => {
      useCatalogueStore.setState({ liveStock: { [searchRow.id]: 7 } });
    });

    const { result } = await renderSearch();

    expect(result.current.term).toBe("mouse");
    expect(result.current.products[0]?.stock).toBe(7);
  });

  it("keeps the search stock when the server pushed nothing for the row", async () => {
    const { result } = await renderSearch();

    expect(result.current.products[0]?.stock).toBe(100);
  });

  it("keeps the search stock for a product the server pushed nothing about", async () => {
    act(() => {
      useCatalogueStore.setState({ liveStock: { 999: 3 } });
    });

    const { result } = await renderSearch();

    expect(result.current.products[0]?.stock).toBe(100);
  });

  it("never mutates the row the API answered with", async () => {
    act(() => {
      useCatalogueStore.setState({ liveStock: { [searchRow.id]: 7 } });
    });

    await renderSearch();

    expect(searchRow.stock).toBe(100);
  });

  it("follows a pushed balance without running the search again", async () => {
    const { result } = await renderSearch();

    expect(result.current.products[0]?.stock).toBe(100);
    expect(vi.mocked(searchSellableProducts)).toHaveBeenCalledTimes(1);

    act(() => {
      useCatalogueStore.setState({ liveStock: { [searchRow.id]: 3 } });
    });

    expect(result.current.products[0]?.stock).toBe(3);

    // The rows on screen are unchanged apart from the stock figure, and the
    // search itself was never repeated.
    expect(result.current.products[0]?.name).toBe("Wireless Mouse");
    expect(vi.mocked(searchSellableProducts)).toHaveBeenCalledTimes(1);
  });
});
