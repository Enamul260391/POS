import { z } from "zod";

import type { Supplier } from "@/types/supplier";

/**
 * Form contract of the Add/Edit supplier dialog (Phase 25).
 *
 * Mirrors `customerSchema`: one schema factory serves validation and typing,
 * closing phone uniqueness over the current directory (excluding the row
 * being edited). The fields are exactly the ones `SupplierResource` accepts —
 * `contactPerson` and `notes` were dropped in Phase 25 because the `suppliers`
 * table has no such columns, and `openingBalance` is a stored figure the form
 * does not edit (it is a server-owned field, exactly like stock on a product).
 */

/** Loose phone shape: digits, spaces and dashes with an optional leading `+`. */
const PHONE_PATTERN = /^\+?[\d\s-]{6,20}$/;

/**
 * Lower-cased digit form of a phone number used for duplicate comparison;
 * `null` when the number is too short to be considered a real contact.
 */
function normalizePhone(phone: string | null): string | null {
  const compact = (phone ?? "").replace(/[^0-9+]/g, "");
  return compact.length >= 6 ? compact.toLowerCase() : null;
}

const supplierFields = {
  name: z
    .string()
    .trim()
    .min(1, "Name is required")
    .max(120, "Name is too long"),
  phone: z
    .string()
    .trim()
    .max(32, "Phone is too long")
    .refine((value) => value === "" || PHONE_PATTERN.test(value), "Enter a valid phone number"),
  email: z
    .string()
    .trim()
    .max(160, "Email is too long")
    .refine(
      (value) => value === "" || z.string().email().safeParse(value).success,
      "Enter a valid email address",
    ),
  address: z.string().trim().max(240, "Address is too long"),
};

/** Form fields the API can be rejected against, for error mapping. */
export const SUPPLIER_FORM_FIELDS = Object.keys(supplierFields);

/** Valid shape of the supplier form. */
export const baseSupplierSchema = z.object(supplierFields);

export type SupplierFormValues = z.infer<typeof baseSupplierSchema>;

/**
 * Schema for one save: identical fields plus phone uniqueness against the
 * directory (excluding the supplier being edited).
 */
export function createSupplierSchema(
  existing: readonly Supplier[],
  editingId: number | null,
): z.ZodEffects<z.ZodObject<typeof supplierFields>> {
  return baseSupplierSchema.superRefine((values, ctx) => {
    const phone = normalizePhone(values.phone);
    if (phone === null) {
      return;
    }
    const duplicate = existing.some(
      (supplier) => supplier.id !== editingId && normalizePhone(supplier.phone) === phone,
    );
    if (duplicate) {
      ctx.addIssue({ code: "custom", message: "Phone number already exists", path: ["phone"] });
    }
  });
}

/**
 * Blank state for "Add supplier".
 *
 * Optional fields start as `""`: the inputs render empty and the store maps
 * them back to `null` on save, keeping the `Supplier` type honest.
 */
export const EMPTY_SUPPLIER_VALUES: SupplierFormValues = {
  name: "",
  phone: "",
  email: "",
  address: "",
};

/** Maps an existing supplier into form state (edit mode). */
export function toSupplierFormValues(supplier: Supplier): SupplierFormValues {
  return {
    name: supplier.name,
    phone: supplier.phone ?? "",
    email: supplier.email ?? "",
    address: supplier.address ?? "",
  };
}