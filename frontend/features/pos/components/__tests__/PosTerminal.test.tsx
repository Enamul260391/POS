import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PosTerminal } from "@/features/pos/components/PosTerminal";
import { useCartStore } from "@/stores/useCartStore";
import { INSUFFICIENT_BATCH_STOCK_MESSAGE, type PriceOption } from "@/types/priceOptions";
import type { Product } from "@/types/product";

/**
 * The POS terminal's batch price selection, end to end.
 *
 * The terminal asks the API for the product's price options, opens the
 * selector when more than one price is eligible, and adds the line with the
 * option the cashier picked. A refused confirmation must be readable while
 * the dialog is still open: the screen behind the modal is covered, so a
 * message rendered there would never be seen.
 */

const { getPriceOptions, product } = vi.hoisted(() => ({
  getPriceOptions: vi.fn(),
  product: {
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
  } satisfies Product,
}));

vi.mock("@/features/pos/services/priceOptionsService", () => ({ getPriceOptions }));

vi.mock("@/features/pos/hooks/usePosProductSearch", () => ({
  usePosProductSearch: () => ({
    products: [product],
    isSearching: false,
    error: null,
    term: "mouse",
  }),
}));

const priceOption = (overrides: Partial<PriceOption> = {}): PriceOption => ({
  selling_price: "400.00",
  batch_id: 1,
  batch_number: "BATCH-1",
  available_quantity: "26",
  ...overrides,
});

/** Opens the selector by tapping the product card. */
async function openPriceSelector() {
  render(<PosTerminal />);

  fireEvent.click(screen.getByRole("button", { name: /Wireless Mouse/ }));

  await waitFor(() => {
    expect(screen.getByRole("dialog")).toBeDefined();
  });
}

beforeEach(() => {
  useCartStore.getState().clearCart();
  getPriceOptions.mockReset();
});

describe("PosTerminal — the batch price selector", () => {
  it("adds the line at the price the cashier confirmed", async () => {
    getPriceOptions.mockResolvedValue([
      priceOption(),
      priceOption({ selling_price: "500.00", batch_id: 14, batch_number: "BATCH-14", available_quantity: "10" }),
    ]);

    await openPriceSelector();

    // The options are listed with their price, batch and available quantity.
    // The queries stay inside the dialog: the product card behind it shows the
    // product's own selling price too.
    const dialog = within(screen.getByRole("dialog"));
    expect(dialog.getByText("৳400.00")).toBeDefined();
    expect(dialog.getByText("Batch BATCH-1")).toBeDefined();
    expect(dialog.getByText("26 units")).toBeDefined();
    expect(dialog.getByText("৳500.00")).toBeDefined();
    expect(dialog.getByText("Batch BATCH-14")).toBeDefined();

    fireEvent.click(dialog.getByRole("radio", { name: /BATCH-14/ }));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    await waitFor(() => {
      expect(screen.queryByRole("dialog")).toBeNull();
    });

    const line = useCartStore.getState().items[0];
    expect(line?.unitPrice).toBe(500);
    expect(line?.batchId).toBe(14);
    expect(line?.quantity).toBe(1);
  });

  it("shows the insufficient-stock message inside the open dialog", async () => {
    // Only half a unit is available at this price, so one unit is refused.
    getPriceOptions.mockResolvedValue([
      priceOption({ available_quantity: "0.5" }),
      priceOption({ selling_price: "500.00", batch_id: 14, batch_number: "BATCH-14", available_quantity: "10" }),
    ]);

    await openPriceSelector();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);

    // The dialog stays open, and the message belongs to it.
    expect(screen.getByRole("dialog")).toBeDefined();
    expect(alert.closest('[role="dialog"]')).not.toBeNull();

    // Nothing was added to the cart.
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it("clears the message when the cashier picks another option", async () => {
    getPriceOptions.mockResolvedValue([
      priceOption({ available_quantity: "0.5" }),
      priceOption({ selling_price: "500.00", batch_id: 14, batch_number: "BATCH-14", available_quantity: "10" }),
    ]);

    await openPriceSelector();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await screen.findByRole("alert");

    fireEvent.click(within(screen.getByRole("dialog")).getByRole("radio", { name: /BATCH-14/ }));

    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("adds the product directly when only one price is eligible", async () => {
    getPriceOptions.mockResolvedValue([priceOption()]);

    render(<PosTerminal />);
    fireEvent.click(screen.getByRole("button", { name: /Wireless Mouse/ }));

    await waitFor(() => {
      expect(useCartStore.getState().items).toHaveLength(1);
    });

    // One price is auto-selected: no selector is opened.
    expect(screen.queryByRole("dialog")).toBeNull();
    const line = useCartStore.getState().items[0];
    expect(line?.unitPrice).toBe(400);
    expect(line?.batchId).toBeNull();
  });
});

describe("PosTerminal — the cart at the batch stock limit", () => {
  it("names the shortfall and adds another batch at an explicitly chosen price", async () => {
    getPriceOptions.mockResolvedValue([
      priceOption({ available_quantity: "26" }),
      priceOption({
        selling_price: "500.00",
        batch_id: 14,
        batch_number: "BATCH-14",
        available_quantity: "10",
      }),
    ]);

    render(<PosTerminal />);

    // 1. Add the product at price A (400).
    fireEvent.click(screen.getByRole("button", { name: /MOUSE-001/ }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.click(within(dialog).getByRole("radio", { name: /\bBATCH-1\b/ }));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    await waitFor(() => {
      expect(useCartStore.getState().items).toHaveLength(1);
    });

    // 2. Reach price A's available quantity (26 units).
    const quantityInput = screen.getByRole("spinbutton", {
      name: "Quantity of Wireless Mouse",
    });
    fireEvent.change(quantityInput, { target: { value: "26" } });
    fireEvent.keyDown(quantityInput, { key: "Enter" });

    await waitFor(() => {
      expect(useCartStore.getState().items[0]?.quantity).toBe(26);
    });

    // 3. The increase is refused — and says why instead of failing silently.
    fireEvent.click(
      screen.getByRole("button", { name: "Increase quantity of Wireless Mouse" }),
    );

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);
    expect(useCartStore.getState().items[0]?.quantity).toBe(26);

    // 4. Another product addition opens the price selector again.
    fireEvent.click(screen.getByRole("button", { name: /MOUSE-001/ }));
    const secondDialog = await screen.findByRole("dialog");

    // 5. Price B is chosen explicitly and confirmed.
    fireEvent.click(
      within(secondDialog).getByRole("radio", { name: /\bBATCH-14\b/ }),
    );
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    await waitFor(() => {
      expect(useCartStore.getState().items).toHaveLength(2);
    });

    // 6. A separate line at price B was added; the price A line is untouched.
    const items = useCartStore.getState().items;
    expect(items[0]?.unitPrice).toBe(400);
    expect(items[0]?.batchId).toBe(1);
    expect(items[0]?.quantity).toBe(26);
    expect(items[1]?.unitPrice).toBe(500);
    expect(items[1]?.batchId).toBe(14);
    expect(items[1]?.quantity).toBe(1);
  });
});
