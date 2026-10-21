import { beforeEach, describe, expect, it, vi } from "vitest";

import { useCartStore } from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";
import type { PriceOption } from "@/types/priceOptions";
import { eligiblePriceOptions, INSUFFICIENT_BATCH_STOCK_MESSAGE } from "@/types/priceOptions";

import {
  addChosenPriceToSale,
  addProductToSale,
  addProductToSaleWithPriceOptions,
  canAddProduct,
  cartQuantityAtPrice,
  cartQuantityOf,
  describeCartFailure,
  remainingStock,
  sellableProductsOf,
  stockStatus,
  type AddToSaleOutcome,
} from "@/features/pos/lib/catalog";

import { getPriceOptions } from "@/features/pos/services/priceOptionsService";

vi.mock("@/stores/useCartStore", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/stores/useCartStore")>();
  return {
    ...actual,
    useCartStore: {
      getState: vi.fn(),
      setState: vi.fn(),
    },
  };
});

vi.mock("@/stores/useProductStore", () => ({
  useCatalogueStore: {
    getState: vi.fn(),
  },
}));

vi.mock("@/features/pos/services/priceOptionsService", () => ({
  getPriceOptions: vi.fn(),
}));

const mockCartStore = vi.mocked(useCartStore.getState);
const mockCatalogueStore = vi.mocked(useCatalogueStore.getState);
const mockGetPriceOptions = vi.mocked(getPriceOptions);

const createMockProduct = (overrides: Partial<Product> = {}): Product => ({
  id: 1,
  name: "Test Product",
  sku: "TEST-001",
  barcode: "123456789",
  categoryId: null,
  categoryName: null,
  costPrice: 50,
  sellingPrice: 100,
  taxRate: 0,
  image: null,
  description: null,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
  unit: "piece",
  stock: 100,
  lowStockThreshold: 10,
  status: "active",
  ...overrides,
});

const createMockPriceOption = (overrides: Partial<PriceOption> = {}): PriceOption => ({
  selling_price: "100.00",
  batch_id: 1,
  batch_number: "BATCH-001",
  available_quantity: "50",
  ...overrides,
});

const createCartItem = (overrides: Partial<{ id: string; productId: number; quantity: number; unitPrice: number; batchId: number | null; priceAvailableQuantity: number | null; discount: number; note: string | null; product: Product }> = {}) => ({
  id: "1",
  productId: 1,
  quantity: 1,
  unitPrice: 100,
  batchId: null,
  priceAvailableQuantity: null,
  discount: 0,
  note: null,
  product: createMockProduct(),
  ...overrides,
});

const createMockCartStore = () => ({
  items: [] as ReturnType<typeof useCartStore.getState>["items"],
  globalDiscount: 0,
  taxRate: 0,
  customerId: null,
  customerName: null,
  addProduct: vi.fn().mockReturnValue({ ok: true, itemIds: ["test-id"], removed: false }),
  addProductByBarcode: vi.fn(),
  removeItem: vi.fn(),
  removeProduct: vi.fn(),
  increaseQuantity: vi.fn(),
  decreaseQuantity: vi.fn(),
  setQuantity: vi.fn(),
  setItemDiscount: vi.fn(),
  setGlobalDiscount: vi.fn(),
  setTaxRate: vi.fn(),
  setCustomer: vi.fn(),
  clearCart: vi.fn(),
});

const createMockCatalogueStore = () => ({
  products: [] as Product[],
  categories: [],
  isLoading: false,
  error: null,
  categoryError: null,
  isLoaded: false,
  hydrate: vi.fn(),
  reload: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  cacheCategory: vi.fn(),
  cacheProducts: vi.fn(),
  clearCache: vi.fn(),
  findById: vi.fn(),
  findByBarcode: vi.fn(),
  findBySku: vi.fn(),
  reset: vi.fn(),
  startRealtimeSync: vi.fn(),
});

beforeEach(() => {
  vi.clearAllMocks();
  mockCartStore.mockReturnValue(createMockCartStore());
  mockCatalogueStore.mockReturnValue(createMockCatalogueStore());
  mockGetPriceOptions.mockReset();
});

function assertOk(result: AddToSaleOutcome): { ok: true; product: Product } {
  expect(result.ok).toBe(true);
  if (!result.ok) throw new Error("Expected ok result");
  return result;
}

function assertFail(result: AddToSaleOutcome): { ok: false; message: string } {
  expect(result.ok).toBe(false);
  if (result.ok) throw new Error("Expected fail result");
  return result;
}

describe("catalog — sellableProductsOf", () => {
  it("filters out inactive products", () => {
    const products = [
      createMockProduct({ id: 1, status: "active" }),
      createMockProduct({ id: 2, status: "inactive" }),
    ];
    const result = sellableProductsOf(products);
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe(1);
  });
});

describe("catalog — cartQuantityOf / cartQuantityAtPrice", () => {
  it("sums quantities for a product across all lines", () => {
    const items = [
      createCartItem({ id: "1", productId: 1, quantity: 2, unitPrice: 100 }),
      createCartItem({ id: "2", productId: 1, quantity: 3, unitPrice: 100 }),
      createCartItem({ id: "3", productId: 2, quantity: 1, unitPrice: 200 }),
    ];
    expect(cartQuantityOf(items, 1)).toBe(5);
    expect(cartQuantityOf(items, 2)).toBe(1);
    expect(cartQuantityOf(items, 999)).toBe(0);
  });

  it("sums quantities for a product at a specific price", () => {
    const items = [
      createCartItem({ id: "1", productId: 1, quantity: 2, unitPrice: 100 }),
      createCartItem({ id: "2", productId: 1, quantity: 3, unitPrice: 100 }),
      createCartItem({ id: "3", productId: 1, quantity: 1, unitPrice: 120 }),
    ];
    expect(cartQuantityAtPrice(items, 1, 100)).toBe(5);
    expect(cartQuantityAtPrice(items, 1, 120)).toBe(1);
  });
});

describe("catalog — stock helpers", () => {
  it("remainingStock returns null for service products", () => {
    const product = createMockProduct({ unit: "service", stock: 0 });
    expect(remainingStock(product, 0)).toBeNull();
  });

  it("remainingStock calculates correctly for tracked products", () => {
    const product = createMockProduct({ stock: 100 });
    expect(remainingStock(product, 0)).toBe(100);
    expect(remainingStock(product, 30)).toBe(70);
    expect(remainingStock(product, 100)).toBe(0);
    expect(remainingStock(product, 120)).toBe(0);
  });

  it("canAddProduct allows service products regardless of stock", () => {
    const product = createMockProduct({ unit: "service", stock: 0 });
    expect(canAddProduct(product, 0, 1)).toBe(true);
    expect(canAddProduct(product, 100, 1)).toBe(true);
  });

  it("canAddProduct blocks inactive products", () => {
    const product = createMockProduct({ status: "inactive", stock: 100 });
    expect(canAddProduct(product, 0, 1)).toBe(false);
  });

  it("canAddProduct blocks when quantity would exceed stock", () => {
    const product = createMockProduct({ stock: 10 });
    expect(canAddProduct(product, 0, 5)).toBe(true);
    expect(canAddProduct(product, 8, 3)).toBe(false);
    expect(canAddProduct(product, 10, 1)).toBe(false);
  });

  it("stockStatus classifies correctly", () => {
    const product = createMockProduct({ stock: 100, lowStockThreshold: 10 });
    expect(stockStatus(product, 0)).toBe("in");
    expect(stockStatus(product, 89)).toBe("in");
    expect(stockStatus(product, 90)).toBe("low");
    expect(stockStatus(product, 95)).toBe("low");
    expect(stockStatus(product, 100)).toBe("out");
    expect(stockStatus(product, 110)).toBe("out");
  });

  it("stockStatus returns service for non-tracked products", () => {
    const product = createMockProduct({ unit: "service" });
    expect(stockStatus(product, 0)).toBe("service");
    expect(stockStatus(product, 100)).toBe("service");
  });
});

describe("catalog — eligiblePriceOptions", () => {
  it("filters out zero-stock options", () => {
    const options: PriceOption[] = [
      createMockPriceOption({ selling_price: "100.00", available_quantity: "50" }),
      createMockPriceOption({ selling_price: "120.00", available_quantity: "0" }),
      createMockPriceOption({ selling_price: "150.00", available_quantity: "10" }),
    ];
    const result = eligiblePriceOptions(options);
    expect(result).toHaveLength(2);
    expect(result.map((o) => o.selling_price)).toEqual(["100.00", "150.00"]);
  });

  it("returns empty array when all options have zero stock", () => {
    const options: PriceOption[] = [
      createMockPriceOption({ selling_price: "100.00", available_quantity: "0" }),
      createMockPriceOption({ selling_price: "120.00", available_quantity: "0" }),
    ];
    const result = eligiblePriceOptions(options);
    expect(result).toHaveLength(0);
  });
});

describe("catalog — describeCartFailure", () => {
  it("returns correct message for each failure reason", () => {
    expect(describeCartFailure("invalid-product")).toBe("Product information is missing.");
    expect(describeCartFailure("product-not-active")).toBe("This product is not active and cannot be sold.");
    expect(describeCartFailure("invalid-quantity")).toBe("That quantity is not valid.");
    expect(describeCartFailure("invalid-unit-price")).toBe("That price is not valid.");
    expect(describeCartFailure("invalid-barcode")).toBe("Scan or type a barcode first.");
    expect(describeCartFailure("product-not-found")).toBe("No product found for this code.");
    expect(describeCartFailure("lookup-failed")).toBe("Product lookup failed. Try again.");
    expect(describeCartFailure("item-not-found")).toBe("That cart line no longer exists.");
    expect(describeCartFailure("price-selection-required")).toBe("This product has multiple prices. Pick one before adding.");
    expect(describeCartFailure("insufficient-batch-stock")).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);
  });
});

describe("catalog — addProductToSale", () => {
  it("rejects null/undefined product", () => {
    const result = addProductToSale(null, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("Product information is missing.");
  });

  it("rejects inactive product", () => {
    const product = createMockProduct({ status: "inactive" });
    const result = addProductToSale(product, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("This product is not active and cannot be sold.");
  });

  it("rejects when stock would be exceeded (out of stock)", () => {
    const product = createMockProduct({ stock: 5 });
    mockCartStore.mockReturnValue({
      ...createMockCartStore(),
      items: [createCartItem({ productId: 1, quantity: 5 })],
    });
    const result = addProductToSale(product, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("Test Product is out of stock.");
  });

  it("rejects when stock would be exceeded (limited remaining)", () => {
    const product = createMockProduct({ stock: 10 });
    mockCartStore.mockReturnValue({
      ...createMockCartStore(),
      items: [createCartItem({ productId: 1, quantity: 8 })],
    });
    const result = addProductToSale(product, 3);
    const failure = assertFail(result);
    expect(failure.message).toBe("Only 2 left in stock.");
  });

  it("adds product successfully when valid", () => {
    const product = createMockProduct();
    const result = addProductToSale(product, 2);
    const success = assertOk(result);
    expect(success.product).toBe(product);
  });
});

describe("catalog — addProductToSaleWithPriceOptions", () => {
  it("bypasses price options for service products", async () => {
    const product = createMockProduct({ unit: "service" });
    const result = await addProductToSaleWithPriceOptions(product, 1);
    const success = assertOk(result);
    expect(success.product).toBe(product);
    expect(mockGetPriceOptions).not.toHaveBeenCalled();
  });

  it("rejects when price options fetch fails with network error", async () => {
    const product = createMockProduct();
    mockGetPriceOptions.mockRejectedValue(new Error("Network error"));
    const result = await addProductToSaleWithPriceOptions(product, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("Network error");
  });

  it("rejects when price options fetch fails with empty message", async () => {
    const product = createMockProduct();
    mockGetPriceOptions.mockRejectedValue(new Error(""));
    const result = await addProductToSaleWithPriceOptions(product, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("Product lookup failed. Try again.");
  });

  it("uses legacy path when no eligible price options (no batch history)", async () => {
    const product = createMockProduct();
    mockGetPriceOptions.mockResolvedValue([]);
    const result = await addProductToSaleWithPriceOptions(product, 1);
    const success = assertOk(result);
    expect(success.product).toBe(product);
  });

  it("auto-selects when exactly one distinct price option exists", async () => {
    const product = createMockProduct();
    mockGetPriceOptions.mockResolvedValue([createMockPriceOption()]);
    const result = await addProductToSaleWithPriceOptions(product, 1);
    const success = assertOk(result);
    expect(success.product).toBe(product);
    expect(mockGetPriceOptions).toHaveBeenCalledWith(product.id);
  });

  it("triggers price selection dialog when multiple distinct prices exist", async () => {
    const product = createMockProduct();
    const options = [
      createMockPriceOption({ selling_price: "100.00", batch_id: 1 }),
      createMockPriceOption({ selling_price: "120.00", batch_id: 2 }),
    ];
    mockGetPriceOptions.mockResolvedValue(options);
    let capturedOptions: PriceOption[] | null = null;
    const result = await addProductToSaleWithPriceOptions(product, 1, (p, opts) => {
      capturedOptions = opts;
    });
    const failure = assertFail(result);
    expect(failure.message).toBe("This product has multiple prices. Pick one before adding.");
    expect(capturedOptions).toEqual(options);
  });
});

describe("catalog — addChosenPriceToSale", () => {
  it("rejects when unit price is negative", () => {
    const product = createMockProduct();
    const option = createMockPriceOption({ selling_price: "-10.00" });
    const result = addChosenPriceToSale(product, option, 1, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("That price is not valid.");
  });

  it("rejects when available quantity is not finite", () => {
    const product = createMockProduct();
    const option = createMockPriceOption({ available_quantity: "invalid" });
    const result = addChosenPriceToSale(product, option, 1, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("That price is not valid.");
  });

  it("rejects when combined available stock at price is insufficient", () => {
    const product = createMockProduct({ stock: 100 });
    const option = createMockPriceOption({ available_quantity: "5" });
    mockCartStore.mockReturnValue({
      ...createMockCartStore(),
      items: [createCartItem({ productId: 1, quantity: 3, unitPrice: 100, priceAvailableQuantity: 5 })],
    });
    const result = addChosenPriceToSale(product, option, 3, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);
  });

  it("adds with batchId=null for auto-selection (single price)", () => {
    const product = createMockProduct();
    const option = createMockPriceOption({ selling_price: "100.00", available_quantity: "50" });
    const result = addChosenPriceToSale(product, option, 1, null);
    const success = assertOk(result);
    expect(success.product).toBe(product);
    expect(mockCartStore().addProduct).toHaveBeenCalledWith(
      product,
      expect.objectContaining({ batchId: null, priceAvailableQuantity: 50 })
    );
  });

  it("adds with explicit batchId for explicit selection (multiple prices)", () => {
    const product = createMockProduct();
    const option = createMockPriceOption({ selling_price: "100.00", batch_id: 42, available_quantity: "50" });
    const result = addChosenPriceToSale(product, option, 1, 42);
    const success = assertOk(result);
    expect(success.product).toBe(product);
    expect(mockCartStore().addProduct).toHaveBeenCalledWith(
      product,
      expect.objectContaining({ batchId: 42, priceAvailableQuantity: 50 })
    );
  });

  it("validates against product stock even when price option has availability", () => {
    const product = createMockProduct({ stock: 5 });
    const option = createMockPriceOption({ available_quantity: "50" });
    mockCartStore.mockReturnValue({
      ...createMockCartStore(),
      items: [createCartItem({ productId: 1, quantity: 5 })],
    });
    const result = addChosenPriceToSale(product, option, 1, 1);
    const failure = assertFail(result);
    expect(failure.message).toBe("Test Product is out of stock.");
  });
});