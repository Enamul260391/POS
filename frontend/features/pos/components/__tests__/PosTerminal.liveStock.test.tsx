import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PosTerminal } from "@/features/pos/components/PosTerminal";
import { searchSellableProducts } from "@/features/products/services/productService";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";

/**
 * The POS terminal against a live stock balance.
 *
 * The terminal's product rows come from its own server-side search, so the
 * realtime balance the backend pushes has to be laid over them — otherwise a
 * purchase or a sale only shows up after the cashier searches again or reloads
 * the page.
 *
 * This test drives the real search hook (only the HTTP service is replaced),
 * then pushes a balance the way the realtime event does, and watches the grid
 * follow it without a second request.
 */

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

const product: Product = {
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

/** Types the search term the cashier would type. */
function search(term: string): void {
  fireEvent.change(screen.getByLabelText("Search products"), { target: { value: term } });
}

/** Pushes a balance the way the realtime stock event does. */
function pushStock(stock: number): void {
  act(() => {
    useCatalogueStore.setState((state) => ({
      liveStock: { ...state.liveStock, [product.id]: stock },
    }));
  });
}

beforeEach(() => {
  useCatalogueStore.getState().reset();
  vi.mocked(searchSellableProducts).mockReset();
  vi.mocked(searchSellableProducts).mockResolvedValue([product]);
});

describe("PosTerminal — a pushed stock balance", () => {
  it("shows the pushed balance without searching again or reloading", async () => {
    render(<PosTerminal />);

    search("mouse");

    // The search answered, and the grid shows the balance it returned.
    await waitFor(
      () => {
        expect(screen.getByText("100 left")).toBeDefined();
      },
      { timeout: 3000 },
    );
    expect(vi.mocked(searchSellableProducts)).toHaveBeenCalledTimes(1);

    // A stock movement arrives over realtime.
    pushStock(42);

    await waitFor(() => {
      expect(screen.getByText("42 left")).toBeDefined();
    });
    expect(screen.queryByText("100 left")).toBeNull();

    // The grid followed the balance on its own: no new request, no reload.
    expect(vi.mocked(searchSellableProducts)).toHaveBeenCalledTimes(1);

    // And a second movement is followed just as well. Seven units is below the
    // low-stock threshold, so the card switches to the "low" badge.
    pushStock(7);

    await waitFor(() => {
      expect(screen.getByText("Low: 7")).toBeDefined();
    });
    expect(vi.mocked(searchSellableProducts)).toHaveBeenCalledTimes(1);
  });

  it("keeps the searched balance when the server pushed nothing", async () => {
    render(<PosTerminal />);

    search("mouse");

    await waitFor(
      () => {
        expect(screen.getByText("100 left")).toBeDefined();
      },
      { timeout: 3000 },
    );

    // A balance for a different product says nothing about this one.
    act(() => {
      useCatalogueStore.setState({ liveStock: { 999: 3 } });
    });

    expect(screen.getByText("100 left")).toBeDefined();
  });
});
