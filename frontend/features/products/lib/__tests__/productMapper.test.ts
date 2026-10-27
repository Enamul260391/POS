import { describe, expect, it } from "vitest";

import { toProduct } from "@/features/products/lib/productMapper";
import type { ProductPayload } from "@/types/product";

/** Full `ProductResource` payload; individual tests override one field. */
function buildPayload(overrides: Partial<ProductPayload>): ProductPayload {
  return {
    id: 7,
    category_id: null,
    name: "Coca-Cola 500ml",
    sku: "BEV-COL-500",
    barcode: "8901234501234",
    unit: "piece",
    low_stock_threshold: 5,
    image: null,
    status: "active",
    current_stock: "12.0000",
    category: null,
    created_at: "2026-01-01T00:00:00.000000Z",
    updated_at: "2026-01-01T00:00:00.000000Z",
    ...overrides,
  };
}

describe("toProduct — product mapping", () => {
  it("maps barcode onto the domain field", () => {
    const product = toProduct(buildPayload({ barcode: "8901234501234" }));
    expect(product.barcode).toBe("8901234501234");
  });

  it("keeps a null barcode as null", () => {
    const product = toProduct(buildPayload({ barcode: null }));
    expect(product.barcode).toBeNull();
  });
});
