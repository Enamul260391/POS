import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  EMPTY_PRODUCT_VALUES,
  type ProductFormValues,
} from "@/features/products/lib/productSchema";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";

// The store is exercised for one thing only: how `createProduct` /
// `updateProduct` map form values onto the service input (`toProductInput`),
// including the SKU trim-and-normalise rule.
const { createProductRequest, updateProductRequest } = vi.hoisted(() => ({
  createProductRequest: vi.fn(),
  updateProductRequest: vi.fn(),
}));

vi.mock("@/features/products/services/productService", () => ({
  createProduct: createProductRequest,
  deleteProduct: vi.fn(),
  getProduct: vi.fn(),
  listAllProducts: vi.fn().mockResolvedValue([]),
  updateProduct: updateProductRequest,
}));

vi.mock("@/features/categories/services/categoryService", () => ({
  listAllCategories: vi.fn().mockResolvedValue([]),
}));

vi.mock("@/lib/realtime", () => ({
  bind: vi.fn(() => () => undefined),
  isSubscribed: vi.fn(() => false),
  onReconnected: vi.fn(() => () => undefined),
  subscribeToBusiness: vi.fn(() => null),
  REALTIME_EVENTS: {
    productCreated: "product.created",
    productUpdated: "product.updated",
    productDeleted: "product.deleted",
    productStockUpdated: "product.stock_updated",
  },
}));

/** A valid form payload; only the SKU varies per test. */
function formValues(overrides: Partial<ProductFormValues>): ProductFormValues {
  return {
    ...EMPTY_PRODUCT_VALUES,
    name: "Coca-Cola 500ml",
    categoryId: 1,
    costPrice: 10,
    sellingPrice: 15,
    lowStockThreshold: 5,
    ...overrides,
  };
}

/** Cached-product stub the mocked service resolves with. */
const savedProduct: Product = {
  id: 11,
  name: "Coca-Cola 500ml",
  sku: "COKE-1",
  barcode: null,
  categoryId: 1,
  categoryName: null,
  costPrice: 10,
  sellingPrice: 15,
  taxRate: 0,
  stock: 0,
  lowStockThreshold: 5,
  unit: "piece",
  status: "active",
  image: null,
  description: null,
  createdAt: "2026-01-01T00:00:00.000000Z",
  updatedAt: "2026-01-01T00:00:00.000000Z",
};

beforeEach(() => {
  createProductRequest.mockReset().mockResolvedValue(savedProduct);
  updateProductRequest.mockReset().mockResolvedValue(savedProduct);
  useCatalogueStore.getState().reset();
});

describe("useProductStore — product input mapping", () => {
  it("trims the SKU before handing it to the service on create", async () => {
    await useCatalogueStore.getState().createProduct(formValues({ sku: "  COKE-1  " }));

    expect(createProductRequest).toHaveBeenCalledWith(
      expect.objectContaining({ sku: "COKE-1" }),
    );
  });

  it("normalises a blank SKU to null on create", async () => {
    await useCatalogueStore.getState().createProduct(formValues({ sku: "   " }));

    expect(createProductRequest).toHaveBeenCalledWith(
      expect.objectContaining({ sku: null }),
    );
  });

  it("sends the trimmed SKU on update", async () => {
    await useCatalogueStore
      .getState()
      .updateProduct(11, formValues({ sku: "EDIT-2" }));

    expect(updateProductRequest).toHaveBeenCalledWith(
      11,
      expect.objectContaining({ sku: "EDIT-2" }),
    );
  });

  it("sends no stock field on create", async () => {
    // A product is master data. Stock enters the system through a purchase, so
    // the create payload must not carry a quantity in any shape.
    await useCatalogueStore.getState().createProduct(formValues({}));

    const input = createProductRequest.mock.calls[0]?.[0] as Record<string, unknown>;
    expect(input).not.toHaveProperty("openingStock");
    expect(input).not.toHaveProperty("opening_stock");
    expect(input).not.toHaveProperty("stock");
  });

  it("sends no stock field on update", async () => {
    await useCatalogueStore.getState().updateProduct(11, formValues({}));

    const input = updateProductRequest.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(input).not.toHaveProperty("openingStock");
    expect(input).not.toHaveProperty("opening_stock");
    expect(input).not.toHaveProperty("stock");
  });

  it("keeps the low-stock threshold, which is an alert level and not a quantity", async () => {
    await useCatalogueStore
      .getState()
      .createProduct(formValues({ lowStockThreshold: 25 }));

    expect(createProductRequest).toHaveBeenCalledWith(
      expect.objectContaining({ lowStockThreshold: 25 }),
    );
  });
});
