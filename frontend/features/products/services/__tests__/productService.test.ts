import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  createProduct,
  updateProduct,
} from "@/features/products/services/productService";
import * as request from "@/lib/api/request";

// The service is the last mapping step before the wire: whatever it hands to
// `apiPost` / `apiPatch` is exactly what Laravel validates and persists.
vi.mock("@/lib/api/request", () => ({
  apiGet: vi.fn(),
  apiList: vi.fn(),
  apiPost: vi.fn().mockResolvedValue({}),
  apiPatch: vi.fn().mockResolvedValue({}),
  apiDelete: vi.fn(),
}));

const post = vi.mocked(request.apiPost);
const patch = vi.mocked(request.apiPatch);

// The shared setup only restores spies; module-level `vi.fn()` call history
// would otherwise leak between tests.
beforeEach(() => {
  post.mockClear();
  patch.mockClear();
});

/** Payload echoed back by the API for any product. */
const echoedPayload = {
  id: 3,
  category_id: null,
  name: "Coke",
  sku: null,
  barcode: null,
  description: null,
  cost_price: "0.00",
  selling_price: "0.00",
  tax_rate: "0.00",
  unit: "pcs",
  low_stock_threshold: 0,
  image: null,
  status: "active",
  current_stock: null,
  created_at: "2026-01-01T00:00:00.000000Z",
  updated_at: "2026-01-01T00:00:00.000000Z",
};

describe("productService — product body", () => {
  it("sends the product body on create", async () => {
    post.mockResolvedValue(echoedPayload as never);

    await createProduct({ name: "Coke", sku: "COKE-1" });

    expect(post).toHaveBeenCalledWith("/products", expect.objectContaining({
      name: "Coke",
      sku: "COKE-1",
    }));
  });

  it("sends the product body on update", async () => {
    patch.mockResolvedValue(echoedPayload as never);

    await updateProduct(3, { name: "Coke", sku: "COKE-2" });

    expect(patch).toHaveBeenCalledWith("/products/3", expect.objectContaining({
      name: "Coke",
      sku: "COKE-2",
    }));
  });
});
