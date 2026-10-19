import { describe, expect, it } from "vitest";

import {
  EMPTY_PURCHASE_LINE,
  EMPTY_PURCHASE_VALUES,
  purchaseSchema,
} from "@/features/purchases/lib/purchaseSchema";

/** A minimal valid form payload. */
const validValues = {
  ...EMPTY_PURCHASE_VALUES,
  supplierId: 1,
  items: [
    {
      productId: 1,
      quantity: 2,
      unitCost: 100,
      discount: 0,
      mrp: Number.NaN,
      sellingPrice: Number.NaN,
      sellingCode: "",
    },
  ],
};

describe("purchaseSchema — resale fields", () => {
  it("accepts a line without MRP, selling price or selling code", () => {
    const result = purchaseSchema.safeParse(validValues);
    expect(result.success).toBe(true);
  });

  it("initialises every new row with all three fields", () => {
    expect(EMPTY_PURCHASE_LINE.mrp).toBeNaN();
    expect(EMPTY_PURCHASE_LINE.sellingPrice).toBeNaN();
    expect(EMPTY_PURCHASE_LINE.sellingCode).toBe("");
    expect(EMPTY_PURCHASE_VALUES.items).toHaveLength(1);
    expect(EMPTY_PURCHASE_VALUES.items[0]).toEqual(EMPTY_PURCHASE_LINE);
  });

  it("accepts populated resale values", () => {
    const result = purchaseSchema.safeParse({
      ...validValues,
      items: [
        {
          productId: 1,
          quantity: 2,
          unitCost: 100,
          discount: 0,
          mrp: 150.5,
          sellingPrice: 140,
          sellingCode: "SELL-001",
        },
      ],
    });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.items[0].mrp).toBe(150.5);
      expect(result.data.items[0].sellingPrice).toBe(140);
      expect(result.data.items[0].sellingCode).toBe("SELL-001");
    }
  });

  it("reads an empty MRP and selling price as absent (null)", () => {
    const result = purchaseSchema.safeParse(validValues);
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.items[0].mrp).toBeNull();
      expect(result.data.items[0].sellingPrice).toBeNull();
    }
  });

  it("trims the selling code", () => {
    const result = purchaseSchema.safeParse({
      ...validValues,
      items: [
        { ...validValues.items[0], sellingCode: "  SELL-001  " },
      ],
    });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.items[0].sellingCode).toBe("SELL-001");
    }
  });

  it("rejects a negative MRP and selling price", () => {
    const mrp = purchaseSchema.safeParse({
      ...validValues,
      items: [{ ...validValues.items[0], mrp: -1 }],
    });
    expect(mrp.success).toBe(false);

    const sellingPrice = purchaseSchema.safeParse({
      ...validValues,
      items: [{ ...validValues.items[0], sellingPrice: -0.01 }],
    });
    expect(sellingPrice.success).toBe(false);
  });

  it("rejects excessive decimal precision on MRP and selling price", () => {
    const mrp = purchaseSchema.safeParse({
      ...validValues,
      items: [{ ...validValues.items[0], mrp: 10.123 }],
    });
    expect(mrp.success).toBe(false);

    const sellingPrice = purchaseSchema.safeParse({
      ...validValues,
      items: [{ ...validValues.items[0], sellingPrice: 10.999 }],
    });
    expect(sellingPrice.success).toBe(false);
  });

  it("rejects a selling code longer than 50 characters", () => {
    const result = purchaseSchema.safeParse({
      ...validValues,
      items: [{ ...validValues.items[0], sellingCode: "A".repeat(51) }],
    });
    expect(result.success).toBe(false);
  });
});
