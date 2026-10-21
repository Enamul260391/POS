import { describe, expect, it } from "vitest";

import { buildSaleInput } from "@/features/pos/lib/checkout";
import type { CartLineTotals, CartTotals } from "@/stores/useCartStore";

/**
 * The sale payload is where the POS and the API meet.
 *
 * The batch contract is the reason these tests exist: a `batch_id` on a sale
 * line means "the cashier chose this selling price", and the API answers with
 * one option per price — each carrying the *baseline* batch of that price and
 * the combined stock available at it. The `batch_id` therefore has to reach
 * the API for a price choice, and it has to stay away for the automatic
 * single-price path, where the API allocates FIFO at the confirmed price on
 * its own.
 */

const line = (overrides: Partial<CartLineTotals> = {}): CartLineTotals => ({
  itemId: "line-1",
  productId: 1,
  quantity: 2,
  unitPrice: 100,
  batchId: null,
  gross: 200,
  discount: 0,
  globalDiscountShare: 0,
  discountTotal: 0,
  taxableBase: 200,
  taxRate: 0,
  taxAmount: 0,
  lineTotal: 200,
  ...overrides,
});

const totals = (lines: CartLineTotals[], overrides: Partial<CartTotals> = {}): CartTotals => ({
  lines,
  lineCount: lines.length,
  quantityTotal: lines.reduce((total, item) => total + item.quantity, 0),
  subtotal: lines.reduce((total, item) => total + item.gross, 0),
  itemDiscountTotal: lines.reduce((total, item) => total + item.discount, 0),
  globalDiscount: 0,
  discountTotal: lines.reduce((total, item) => total + item.discountTotal, 0),
  taxRate: 0,
  taxTotal: lines.reduce((total, item) => total + item.taxAmount, 0),
  grandTotal: lines.reduce((total, item) => total + item.lineTotal, 0),
  ...overrides,
});

const build = (cartTotals: CartTotals) =>
  buildSaleInput({
    totals: cartTotals,
    customerId: null,
    method: "cash",
    paid: { grandTotal: cartTotals.grandTotal, paidAmount: cartTotals.grandTotal },
    now: new Date("2026-01-15T10:00:00Z"),
  });

describe("buildSaleInput — the selected price", () => {
  it("sends the unit price the cashier confirmed", () => {
    const input = build(totals([line({ unitPrice: 120 })]));

    expect(input.items[0].unitPrice).toBe(120);
  });

  it("sends the quantity of the line unchanged", () => {
    const input = build(totals([line({ quantity: 3, gross: 300, lineTotal: 300 })]));

    expect(input.items[0].quantity).toBe(3);
  });
});

describe("buildSaleInput — the batch id", () => {
  it("carries the batch id of a price choice into the sale request", () => {
    // The price-options endpoint hands the POS selector the baseline batch of
    // each price; that id is what identifies the chosen price on the wire.
    const input = build(totals([line({ unitPrice: 120, batchId: 42 })]));

    expect(input.items[0].batch_id).toBe(42);
  });

  it("omits the batch id for an automatic single-price selection", () => {
    // No `batch_id` means "the confirmed price", and the API allocates FIFO
    // across every eligible batch at that price.
    const input = build(totals([line({ unitPrice: 100, batchId: null })]));

    expect(input.items[0].batch_id ?? null).toBeNull();
    expect("batch_id" in input.items[0]).toBe(false);
  });

  it("omits the batch id for a legacy line without batch history", () => {
    const input = build(totals([line({ unitPrice: 100, batchId: null })]));

    expect("batch_id" in input.items[0]).toBe(false);
  });

  it("keeps each line's own batch id when several lines are present", () => {
    const input = build(
      totals([
        line({ itemId: "a", productId: 1, unitPrice: 100, batchId: null }),
        line({ itemId: "b", productId: 2, unitPrice: 120, batchId: 42 }),
      ]),
    );

    expect("batch_id" in input.items[0]).toBe(false);
    expect(input.items[1].batch_id).toBe(42);
  });
});

describe("buildSaleInput — the money", () => {
  it("sends the item-wise discount per line and the global discount once", () => {
    const input = build(
      totals(
        [
          line({ itemId: "a", gross: 200, discount: 20, globalDiscountShare: 10, discountTotal: 30, taxableBase: 170, lineTotal: 170 }),
          line({ itemId: "b", productId: 2, gross: 100, discount: 0, globalDiscountShare: 5, discountTotal: 5, taxableBase: 95, lineTotal: 95 }),
        ],
        { globalDiscount: 15, discountTotal: 35 },
      ),
    );

    expect(input.items[0].discount).toBe(20);
    expect(input.items[1].discount).toBe(0);
    // The invoice discount is charged once, never per line.
    expect(input.discount).toBe(15);
  });

  it("sends the line tax per line and leaves the invoice tax at zero", () => {
    // Sending both would count the VAT twice.
    const input = build(
      totals(
        [line({ gross: 200, taxAmount: 20, taxableBase: 200, lineTotal: 220 })],
        { taxTotal: 20 },
      ),
    );

    expect(input.items[0].tax).toBe(20);
    expect(input.tax).toBe(0);
  });

  it("never sends totals the API recomputes itself", () => {
    const input = build(totals([line({ gross: 200, lineTotal: 200 })]));

    expect(input).not.toHaveProperty("subtotal");
    expect(input).not.toHaveProperty("grand_total");
    expect(input).not.toHaveProperty("invoice_number");
  });

  it("settles the invoice for exactly the amount due", () => {
    const input = build(totals([line({ gross: 200, lineTotal: 200 })]));

    expect(input.paidAmount).toBe(200);
  });
});

describe("buildSaleInput — the date", () => {
  it("sends a local calendar date", () => {
    const input = build(totals([line()]));

    expect(input.saleDate).toBe("2026-01-15");
  });
});
