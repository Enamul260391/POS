import { z } from "zod";

/**
 * Form contract of the Create Purchase dialog (Phase 9).
 *
 * One schema validates the whole order — supplier, line items and payment —
 * for React Hook Form. The service re-validates against the product master
 * before writing anything, so this schema owns *shape* and the service owns
 * *domain* rules; both share the same `calculatePurchaseTotals` for money.
 */

/** One line as the form holds it (before product snapshotting). */
const lineSchema = z.object({
  productId: z
    .number({ invalid_type_error: "Select a product" })
    .int("Select a product")
    .min(1, "Select a product"),
  quantity: z
    .number({ invalid_type_error: "Required" })
    .positive("Must be more than 0"),
  unitCost: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
  discount: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
});

const purchaseFields = {
  supplierId: z
    .number({ invalid_type_error: "Select a supplier" })
    .int("Select a supplier")
    .min(1, "Select a supplier"),
  taxRate: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative")
    .max(100, "Maximum is 100%"),
  paidAmount: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative"),
  note: z.string().trim().max(500, "Notes are too long"),
  items: z.array(lineSchema).min(1, "Add at least one product"),
};

/**
 * Valid shape of the purchase form plus the cross-line rules: one product
 * per line (the UI merges quantities) and a discount that never exceeds the
 * line it applies to.
 */
export const purchaseSchema = z
  .object(purchaseFields)
  .superRefine((values, ctx) => {
    const seen = new Set<number>();
    values.items.forEach((item, index) => {
      if (item.productId >= 1) {
        if (seen.has(item.productId)) {
          ctx.addIssue({
            code: "custom",
            message: "Product already in the list — adjust its line instead.",
            path: ["items", index, "productId"],
          });
        }
        seen.add(item.productId);
      }
      const gross = item.quantity * item.unitCost;
      if (item.discount > gross + 0.001) {
        ctx.addIssue({
          code: "custom",
          message: `Discount cannot exceed the line total (${gross.toFixed(2)})`,
          path: ["items", index, "discount"],
        });
      }
    });
  });

export type PurchaseFormValues = z.infer<typeof purchaseSchema>;

/** One line of `PurchaseFormValues.items`. */
export type PurchaseLineFormValues = PurchaseFormValues["items"][number];

/**
 * Blank line for "Add row": quantity starts at 1 (the usual restock) and the
 * cost is `NaN`, which the number input renders empty — a forgotten cost then
 * fails validation as "Required" instead of silently ordering for free. The
 * dialog pre-fills it from `product.costPrice` when a product is picked.
 */
export const EMPTY_PURCHASE_LINE: PurchaseLineFormValues = {
  productId: 0,
  quantity: 1,
  unitCost: Number.NaN,
  discount: 0,
};

/** Blank state for "New purchase": one line, no tax, unpaid. */
export const EMPTY_PURCHASE_VALUES: PurchaseFormValues = {
  supplierId: 0,
  taxRate: 0,
  paidAmount: 0,
  note: "",
  items: [{ ...EMPTY_PURCHASE_LINE }],
};