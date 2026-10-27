import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";

import { CartItem } from "@/features/pos/components/CartItem";
import { useCartItems, useCartStore, useCartTotals } from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import { INSUFFICIENT_BATCH_STOCK_MESSAGE } from "@/types/priceOptions";
import type { Product } from "@/types/product";

/**
 * The cart line's quantity controls.
 *
 * A line is capped twice: by the product's total ledger stock and by the
 * quantity still available at the line's own selling price. The second limit
 * used to disable the increase button, so the cashier got a dead button and no
 * explanation. The store always refused the change — the line simply now says
 * why, inside the line, with the backend's exact wording.
 *
 * The cart is rendered through a small harness that mirrors `CartPanel`, so
 * the tests always see the live store values (a refused change must leave the
 * screen showing the stored quantity, not a stale prop).
 */

const PRODUCT: Product = {
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

/** The cart panel's shape: one `CartItem` per store line, with its totals. */
function CartHarness() {
  const items = useCartItems();
  const totals = useCartTotals();

  return (
    <ul>
      {items.map((item) => {
        const lineTotals = totals.lines.find((entry) => entry.itemId === item.id);
        return lineTotals === undefined ? null : (
          <CartItem key={item.id} line={item} totals={lineTotals} />
        );
      })}
    </ul>
  );
}

/** Puts one product into the cart at one selling price group. */
function addLine(options: {
  product?: Product;
  quantity: number;
  unitPrice: number;
  batchId: number;
  priceAvailableQuantity: number;
}): void {
  const result = useCartStore.getState().addProduct(options.product ?? PRODUCT, {
    quantity: options.quantity,
    unitPrice: options.unitPrice,
    batchId: options.batchId,
    priceAvailableQuantity: options.priceAvailableQuantity,
  });

  if (!result.ok) {
    throw new Error(`the fixture line was refused: ${result.reason}`);
  }
}

/** The quantity field of the (only) line. */
function quantityField(): HTMLInputElement {
  return screen.getByRole("spinbutton", {
    name: "Quantity of Wireless Mouse",
  }) as HTMLInputElement;
}

/** Types a quantity and commits it with Enter, as the cashier does. */
function commitQuantity(value: string): void {
  const input = quantityField();
  fireEvent.change(input, { target: { value } });
  fireEvent.keyDown(input, { key: "Enter" });
}

beforeEach(() => {
  useCartStore.getState().clearCart();
  useCatalogueStore.setState({ products: [] });
  useCatalogueStore.getState().cacheProducts([PRODUCT]);
});

describe("CartItem — the batch stock limit", () => {
  it("reports the batch shortfall while the product still has total stock", () => {
    // 26 of 26 units are taken at this selling price, but the product still
    // has 74 units in total: another price group can serve them.
    addLine({
      quantity: 26,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    render(<CartHarness />);

    fireEvent.click(
      screen.getByRole("button", { name: "Increase quantity of Wireless Mouse" }),
    );

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);

    // The refused increase changed nothing.
    expect(useCartStore.getState().items[0]?.quantity).toBe(26);
    expect(quantityField().value).toBe("26");
  });

  it("keeps the stored quantity when a bigger number is committed", () => {
    addLine({
      quantity: 5,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    render(<CartHarness />);

    commitQuantity("30");

    expect(screen.getByRole("alert").textContent).toBe(
      INSUFFICIENT_BATCH_STOCK_MESSAGE,
    );

    // The stored quantity survives the refusal and the field snaps back to it.
    expect(useCartStore.getState().items[0]?.quantity).toBe(5);
    expect(quantityField().value).toBe("5");
  });

  it("clears the message after a successful quantity change", () => {
    addLine({
      quantity: 26,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    render(<CartHarness />);

    fireEvent.click(
      screen.getByRole("button", { name: "Increase quantity of Wireless Mouse" }),
    );
    expect(screen.getByRole("alert")).toBeDefined();

    commitQuantity("25");

    expect(screen.queryByRole("alert")).toBeNull();
    expect(useCartStore.getState().items[0]?.quantity).toBe(25);
  });

  it("clears the previous error when an increase succeeds", () => {
    addLine({
      quantity: 25,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    render(<CartHarness />);

    // A refused commit leaves the line one unit below its price limit, with
    // the message on screen.
    commitQuantity("30");
    expect(screen.getByRole("alert").textContent).toBe(
      INSUFFICIENT_BATCH_STOCK_MESSAGE,
    );
    expect(useCartStore.getState().items[0]?.quantity).toBe(25);

    // The increase that now succeeds clears the message.
    fireEvent.click(
      screen.getByRole("button", { name: "Increase quantity of Wireless Mouse" }),
    );

    expect(screen.queryByRole("alert")).toBeNull();
    expect(useCartStore.getState().items[0]?.quantity).toBe(26);
  });

  it("never takes stock from another selling price group", () => {
    // Two price groups of the same product: 400 (26 units) and 500 (10 units).
    addLine({
      quantity: 26,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    addLine({
      quantity: 1,
      unitPrice: 500,
      batchId: 14,
      priceAvailableQuantity: 10,
    });
    render(<CartHarness />);

    const increaseButtons = screen.getAllByRole("button", {
      name: "Increase quantity of Wireless Mouse",
    });
    expect(increaseButtons).toHaveLength(2);

    fireEvent.click(increaseButtons[0]);

    // One message for the one refused action.
    expect(screen.getAllByRole("alert")).toHaveLength(1);
    expect(screen.getByRole("alert").textContent).toBe(
      INSUFFICIENT_BATCH_STOCK_MESSAGE,
    );

    // Neither line was touched: no units moved between the price groups.
    const items = useCartStore.getState().items;
    expect(items).toHaveLength(2);
    expect(items[0]?.unitPrice).toBe(400);
    expect(items[0]?.quantity).toBe(26);
    expect(items[0]?.batchId).toBe(1);
    expect(items[1]?.unitPrice).toBe(500);
    expect(items[1]?.quantity).toBe(1);
    expect(items[1]?.batchId).toBe(14);
  });

  it("reports Stock Out when the total product stock is exhausted", () => {
    // The product has 26 units in total and all of them are in the cart:
    // nothing more can be sold at any price.
    const scarce: Product = { ...PRODUCT, id: 47, name: "USB Cable", stock: 26 };
    useCatalogueStore.getState().cacheProducts([scarce]);
    addLine({
      product: scarce,
      quantity: 26,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    render(<CartHarness />);

    fireEvent.click(
      screen.getByRole("button", { name: "Increase quantity of USB Cable" }),
    );

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe("Stock Out");

    // The refused increase left the line exactly as it was.
    const line = useCartStore.getState().items[0];
    expect(line?.quantity).toBe(26);
    expect(line?.unitPrice).toBe(400);
    expect(line?.batchId).toBe(1);
  });

  it("keeps the increase button disabled when the product is no longer sellable", () => {
    addLine({
      quantity: 1,
      unitPrice: 400,
      batchId: 1,
      priceAvailableQuantity: 26,
    });
    // The product was deactivated after it was added to the cart.
    useCatalogueStore.setState((state) => ({
      products: state.products.map((entry) =>
        entry.id === PRODUCT.id ? { ...entry, status: "inactive" } : entry,
      ),
    }));
    render(<CartHarness />);

    const button = screen.getByRole("button", {
      name: "Increase quantity of Wireless Mouse",
    }) as HTMLButtonElement;

    expect(button.disabled).toBe(true);
    expect(useCartStore.getState().items[0]?.quantity).toBe(1);
  });
});