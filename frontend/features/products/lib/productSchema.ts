import { z } from "zod";

import type { Product, ProductStatus, ProductUnit } from "@/types/product";

/**
 * Form contract of the Add/Edit product dialog (Phase 7, Phase 25).
 *
 * One schema factory serves validation and typing.
 *
 * Phase 25 changes, all driven by `StoreProductRequest` / `UpdateProductRequest`:
 *   - `status` offers only `active` / `inactive`; `discontinued` is not a value
 *     the API accepts.
 *   - `sku` is optional (the column is nullable and only unique when present).
 *   - `categoryId` is optional too (`category_id` is `nullable`), so a product may
 *     be created uncategorised.
 *   - SKU/barcode uniqueness is no longer checked here: the API enforces it
 *     per business with a `422`, which the form maps onto the field. Checking it
 *     client-side would need the whole catalogue in memory for no benefit.
 */

/** Every unit the product form offers (all values of `ProductUnit`). */
const UNIT_VALUES = [
  "piece",
  "pack",
  "box",
  "carton",
  "dozen",
  "kilogram",
  "gram",
  "liter",
  "milliliter",
  "bottle",
  "service",
] as const satisfies readonly ProductUnit[];

const UNIT_LABELS: Record<ProductUnit, string> = {
  piece: "Piece",
  pack: "Pack",
  box: "Box",
  carton: "Carton",
  dozen: "Dozen",
  kilogram: "Kilogram",
  gram: "Gram",
  liter: "Liter",
  milliliter: "Milliliter",
  bottle: "Bottle",
  service: "Service",
};

/** Select options for the unit field. */
export const UNIT_OPTIONS: readonly { value: ProductUnit; label: string }[] =
  UNIT_VALUES.map((value) => ({ value, label: UNIT_LABELS[value] }));

/** Select options for the status field (form and filters share them). */
export const STATUS_OPTIONS: readonly { value: ProductStatus; label: string }[] = [
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

const productFields = {
  name: z
    .string()
    .trim()
    .min(1, "Name is required")
    .max(150, "Name is too long"),
  sku: z
    .string()
    .trim()
    .max(50, "SKU is too long")
    .refine(
      (value) => value === "" || !/\s/.test(value),
      "SKU cannot contain spaces",
    ),
  barcode: z.string().trim().max(100, "Barcode is too long"),
  categoryId: z
    .number({ invalid_type_error: "Select a category" })
    .int("Select a category")
    .min(1, "Select a category")
    // `0` is the "not chosen" sentinel of the select; the API accepts a null
    // category, so an untouched field is valid.
    .refine((value) => value === 0 || value > 0, "Select a category"),
  costPrice: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
  sellingPrice: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
  unit: z.enum(UNIT_VALUES),
  lowStockThreshold: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
  status: z.enum(["active", "inactive"]),
};

/** Valid shape of the product form. */
export const baseProductSchema = z.object(productFields);

export type ProductFormValues = z.infer<typeof baseProductSchema>;

/**
 * Blank state for "Add product".
 *
 * Numeric fields start as `NaN`: the number inputs render empty and a
 * forgotten price fails validation with "Required" instead of silently
 * saving a free product. `categoryId` starts at `0` ("not chosen").
 *
 * There is deliberately **no** stock field: a product carries master data only,
 * and every unit enters the system through a purchase.
 */
export const EMPTY_PRODUCT_VALUES: ProductFormValues = {
  name: "",
  sku: "",
  barcode: "",
  categoryId: 0,
  costPrice: Number.NaN,
  sellingPrice: Number.NaN,
  unit: "piece",
  lowStockThreshold: Number.NaN,
  status: "active",
};

/** Maps an existing product into form state (edit mode). */
export function toProductFormValues(product: Product): ProductFormValues {
  return {
    name: product.name,
    sku: product.sku ?? "",
    barcode: product.barcode ?? "",
    categoryId: product.categoryId ?? 0,
    costPrice: product.costPrice,
    sellingPrice: product.sellingPrice,
    unit: product.unit,
    lowStockThreshold: product.lowStockThreshold,
    status: product.status,
  };
}
