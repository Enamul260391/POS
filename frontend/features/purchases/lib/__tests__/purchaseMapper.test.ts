import { describe, expect, it } from "vitest";

import {
  toPurchase,
  toPurchaseItem,
} from "@/features/purchases/lib/purchaseMapper";
import {
  calculatePurchaseTotals,
  lineTotalOf,
} from "@/features/purchases/lib/purchases";
import type {
  PurchaseItemPayload,
  PurchasePayload,
} from "@/types/purchase";

/** Full `PurchaseItemResource` payload; individual tests override fields. */
function buildItemPayload(overrides: Partial<PurchaseItemPayload>): PurchaseItemPayload {
  return {
    id: 1,
    purchase_id: 10,
    product_id: 7,
    quantity: "2",
    unit_cost: "100.00",
    discount: "5.00",
    mrp: "150.50",
    selling_price: "140.00",
    selling_code: "SELL-001",
    tax: "0.00",
    total: "195.00",
    product: {
      id: 7,
      name: "Test Product",
      sku: "SKU-1",
      unit: "piece",
    },
    ...overrides,
  };
}

/** Full `PurchaseResource` payload with one line. */
function buildPurchasePayload(
  overrides: Partial<PurchasePayload>,
  itemOverrides: Partial<PurchaseItemPayload> = {},
): PurchasePayload {
  return {
    id: 10,
    supplier_id: 3,
    invoice_number: "INV-1",
    purchase_date: "2026-10-19",
    subtotal: "195.00",
    discount: "0.00",
    tax: "0.00",
    grand_total: "195.00",
    paid_amount: "0.00",
    due_amount: "195.00",
    payment_status: "pending",
    status: "draft",
    notes: null,
    created_by: null,
    supplier: { id: 3, name: "Supplier" },
    items: [buildItemPayload(itemOverrides)],
    created_at: "2026-10-19T00:00:00.000000Z",
    updated_at: "2026-10-19T00:00:00.000000Z",
    ...overrides,
  };
}

describe("toPurchaseItem — resale fields", () => {
  it("preserves populated MRP, selling price and selling code", () => {
    const item = toPurchaseItem(buildItemPayload({}));
    expect(item.mrp).toBe(150.5);
    expect(item.sellingPrice).toBe(140);
    expect(item.sellingCode).toBe("SELL-001");
  });

  it("maps missing and null values to null without NaN", () => {
    const item = toPurchaseItem(
      buildItemPayload({ mrp: null, selling_price: null, selling_code: null }),
    );
    expect(item.mrp).toBeNull();
    expect(item.sellingPrice).toBeNull();
    expect(item.sellingCode).toBeNull();

    // A legacy payload that omits the keys entirely must read the same.
    const legacy = buildItemPayload({});
    delete (legacy as Partial<PurchaseItemPayload>).mrp;
    delete (legacy as Partial<PurchaseItemPayload>).selling_price;
    delete (legacy as Partial<PurchaseItemPayload>).selling_code;
    const mapped = toPurchaseItem(legacy as PurchaseItemPayload);
    expect(mapped.mrp).toBeNull();
    expect(mapped.sellingPrice).toBeNull();
    expect(mapped.sellingCode).toBeNull();
    expect(Number.isNaN(mapped.mrp)).toBe(false);
    expect(Number.isNaN(mapped.sellingPrice)).toBe(false);
  });

  it("keeps line totals based on cost and discount only", () => {
    const withPricing = toPurchaseItem(
      buildItemPayload({ mrp: "9999.99", selling_price: "8888.88" }),
    );
    const withoutPricing = toPurchaseItem(
      buildItemPayload({ mrp: null, selling_price: null }),
    );
    expect(withPricing.lineTotal).toBe(withoutPricing.lineTotal);
    expect(withPricing.lineTotal).toBe(195);
  });
});

describe("toPurchase — resale fields", () => {
  it("maps populated and missing values per line", () => {
    const purchase = toPurchase(buildPurchasePayload({}));
    expect(purchase.items).toHaveLength(1);
    expect(purchase.items[0].mrp).toBe(150.5);
    expect(purchase.items[0].sellingPrice).toBe(140);
    expect(purchase.items[0].sellingCode).toBe("SELL-001");

    const historic = toPurchase(
      buildPurchasePayload({}, { mrp: null, selling_price: null, selling_code: null }),
    );
    expect(historic.items[0].mrp).toBeNull();
    expect(historic.items[0].sellingPrice).toBeNull();
    expect(historic.items[0].sellingCode).toBeNull();
  });
});

describe("purchase totals ignore resale fields", () => {
  it("line totals do not change when MRP, selling price or code change", () => {
    const base = { quantity: 2, unitCost: 100, discount: 5 };
    expect(lineTotalOf(base)).toBe(195);
    expect(
      lineTotalOf({ ...base, mrp: 9999, sellingPrice: 8888 } as typeof base & {
        mrp: number;
        sellingPrice: number;
      }),
    ).toBe(195);
  });

  it("purchase totals do not change when resale fields change", () => {
    const lines = [{ quantity: 2, unitCost: 100, discount: 5 }];
    const totals = calculatePurchaseTotals({ lines, taxRate: 0, paidAmount: 0 });
    expect(totals.subtotal).toBe(200);
    expect(totals.discountTotal).toBe(5);
    expect(totals.grandTotal).toBe(195);
  });
});
