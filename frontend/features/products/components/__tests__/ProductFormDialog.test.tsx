import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ProductFormDialog } from "@/features/products/components/ProductFormDialog";
import type { Product } from "@/types/product";

// The dialog talks to the catalogue store only through `getState()` and the
// `selectCategories` selector, so a tiny stub keeps the test independent of the
// API, axios and the realtime layer.
const { createProduct, updateProduct } = vi.hoisted(() => ({
  createProduct: vi.fn<(...args: unknown[]) => Promise<unknown>>().mockResolvedValue({}),
  updateProduct: vi.fn<(...args: unknown[]) => Promise<unknown>>().mockResolvedValue({}),
}));

vi.mock("@/stores/useProductStore", () => {
  const state = {
    products: [],
    // One category so the add form can make a valid selection.
    categories: [{ id: 1, name: "Drinks", slug: "drinks" }],
    createProduct,
    updateProduct,
    cacheCategory: vi.fn(),
  };
  const useCatalogueStore = (selector: (s: typeof state) => unknown) => selector(state);
  useCatalogueStore.getState = () => state;
  return {
    useCatalogueStore,
    selectCategories: (s: typeof state) => s.categories,
  };
});

// Only mounted by the quick-create action; never exercised here.
vi.mock("@/features/categories/components/CreateCategoryDialog", () => ({
  CreateCategoryDialog: () => null,
}));

/** Product fixture shaped exactly like the API-mapped `Product`. */
function buildProduct(overrides: Partial<Product>): Product {
  return {
    id: 1,
    name: "Coca-Cola 500ml",
    sku: "BEV-COL-500",
    barcode: "8901234501234",
    // The form's category rule requires a chosen category (id > 0).
    categoryId: 1,
    categoryName: "Drinks",
    stock: 12,
    lowStockThreshold: 5,
    unit: "piece",
    status: "active",
    image: null,
    createdAt: "2026-01-01T00:00:00.000000Z",
    updatedAt: "2026-01-01T00:00:00.000000Z",
    ...overrides,
  };
}

/** Visible label texts, in DOM order. */
function labelTexts(): string[] {
  return Array.from(document.querySelectorAll("label")).map(
    (label) => label.textContent ?? "",
  );
}

/** Opens the Category select and picks the one real option. */
async function selectFirstCategory(): Promise<void> {
  fireEvent.click(screen.getByLabelText("Category"));
  const option = await screen.findByRole("option", { name: "Drinks" }, { timeout: 10_000 });
  fireEvent.click(option);
}

afterEach(() => {
  createProduct.mockClear();
  updateProduct.mockClear();
});

describe("ProductFormDialog — product fields", () => {
  it("follows the mandated field order", () => {
    render(<ProductFormDialog target={{ mode: "add" }} onOpenChange={vi.fn()} />);

    const labels = labelTexts();
    const indexOf = (text: string) => {
      const index = labels.indexOf(text);
      expect(index, `expected a "${text}" label`).toBeGreaterThanOrEqual(0);
      return index;
    };

    // The exact order the Product form must follow.
    const order = [
      "Product name",
      "SKU",
      "Barcode (optional)",
      "Category",
      "Unit",
      "Status",
      "Low stock threshold",
    ].map(indexOf);

    expect(order).toEqual([...order].sort((a, b) => a - b));
    expect(indexOf("Category")).toBe(indexOf("Barcode (optional)") + 1);
  });

  // Base-ui's select popup makes this the slowest dialog test; under full-suite
  // parallel load it needs more than the default 5s vitest timeout.
  it("submits the entered product on add", async () => {
    render(<ProductFormDialog target={{ mode: "add" }} onOpenChange={vi.fn()} />);

    fireEvent.change(screen.getByLabelText("Product name"), {
      target: { value: "New Product" },
    });
    // Required numeric fields, filled with the same values the form expects.
    for (const label of ["Low stock threshold"]) {
      fireEvent.change(screen.getByLabelText(label), { target: { value: "10" } });
    }
    await selectFirstCategory();

    fireEvent.click(screen.getByRole("button", { name: "Save product" }));

    await waitFor(() => expect(createProduct).toHaveBeenCalledTimes(1), { timeout: 10_000 });
    expect(createProduct.mock.calls[0]?.[0]).toMatchObject({
      name: "New Product",
    });
  }, 15_000);

  it("loads the existing product in edit mode and submits changes", async () => {
    const product = buildProduct({ name: "Edited Product" });
    render(<ProductFormDialog target={{ mode: "edit", product }} onOpenChange={vi.fn()} />);

    const input = screen.getByLabelText("Product name") as HTMLInputElement;
    expect(input.value).toBe("Edited Product");

    fireEvent.change(input, { target: { value: "Edited Product 2" } });
    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(updateProduct).toHaveBeenCalledTimes(1));
    expect(updateProduct.mock.calls[0]?.[1]).toMatchObject({
      name: "Edited Product 2",
    });
  });

  it("keeps the entered name when validation fails", async () => {
    render(<ProductFormDialog target={{ mode: "add" }} onOpenChange={vi.fn()} />);

    fireEvent.change(screen.getByLabelText("Product name"), {
      target: { value: "Needs Threshold" },
    });

    // Low stock threshold left empty → "Required" failure on submit.
    fireEvent.click(screen.getByRole("button", { name: "Save product" }));

    await waitFor(() => {
      expect(screen.getAllByText("Required").length).toBeGreaterThan(0);
    });

    // The dialog never closes on failure and the entered data survives.
    expect(createProduct).not.toHaveBeenCalled();
    expect((screen.getByLabelText("Product name") as HTMLInputElement).value).toBe("Needs Threshold");
  });
});
