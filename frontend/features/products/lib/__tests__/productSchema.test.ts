import { describe, expect, it } from "vitest";

import {
  baseProductSchema,
  EMPTY_PRODUCT_VALUES,
  toProductFormValues,
} from "@/features/products/lib/productSchema";
import type { Product } from "@/types/product";

/** A minimal valid form payload with sensible numeric defaults. */
const validValues = {
  ...EMPTY_PRODUCT_VALUES,
  name: "Coca-Cola 500ml",
  // The form's category rule requires a chosen category (id > 0).
  categoryId: 1,
  costPrice: 10,
  sellingPrice: 15,
  lowStockThreshold: 5,
};

/** Product fixture shaped exactly like the API-mapped `Product`. */
function buildProduct(overrides: Partial<Product>): Product {
  return {
    id: 1,
    name: "Coca-Cola 500ml",
    sku: "BEV-COL-500",
    barcode: "8901234501234",
    categoryId: null,
    categoryName: null,
    costPrice: 10,
    sellingPrice: 15,
    taxRate: 0,
    stock: 12,
    lowStockThreshold: 5,
    unit: "piece",
    status: "active",
    image: null,
    description: null,
    createdAt: "2026-01-01T00:00:00.000000Z",
    updatedAt: "2026-01-01T00:00:00.000000Z",
    ...overrides,
  };
}

describe("productSchema — product form", () => {
  it("accepts a minimal valid payload", () => {
    const result = baseProductSchema.safeParse(validValues);
    expect(result.success).toBe(true);
  });

  it("trims surrounding whitespace before validating", () => {
    const result = baseProductSchema.safeParse({
      ...validValues,
      name: "  Coca-Cola 500ml  ",
    });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.name).toBe("Coca-Cola 500ml");
    }
  });
});

describe("toProductFormValues — product form", () => {
  it("maps a null barcode to an empty string without breaking", () => {
    const values = toProductFormValues(buildProduct({ barcode: null }));
    expect(values.barcode).toBe("");
  });
});
