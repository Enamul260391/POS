import { z } from "zod";

/**
 * Form contract of the Create Purchase dialog (Phase 9).
 *
 * One schema validates the whole order — supplier, line items and payment —
 * for React Hook Form. The service re-validates against the product master
 * before writing anything, so this schema owns *shape* and the service owns
 * *domain* rules; both share the same `calculatePurchaseTotals` for money.
 *
 * Each line also carries the purchase-specific resale fields — MRP, selling
 * price and selling code — immediately after discount. They are optional,
 * never affect totals, and are never prefilled from the global product.
 */

/** Rounds to two decimals, mirroring the API's `decimal:0,2` rule. */
function toTwoDecimals(value: number): number {
  return Math.round(value * 100) / 100;
}

/**
 * Optional money field as the form holds it.
 *
 * React Hook Form registers numeric inputs with `valueAsNumber`, so an empty
 * field arrives as `NaN` and must read as "absent" (`null`), not `0` — a
 * forgotten MRP must not silently become a free price. The input side accepts
 * `number | null | undefined` (so defaults and empty fields type-check) and
 * the output side is always `number | null`; `NaN`/missing become `null`
 * while finite numbers keep the `min(0)` and two-decimal rules so messages
 * stay actionable instead of a raw API 422.
 */
const optionalMoneySchema: z.ZodType<
  number | null,
  z.ZodTypeDef,
  number | null | undefined
> = z
  .union([z.number(), z.null(), z.undefined(), z.nan()])
  .transform((value) =>
    typeof value !== "number" || Number.isNaN(value) ? null : value,
  )
  .refine((value) => value === null || value >= 0, "Cannot be negative")
  .refine(
    (value) => value === null || toTwoDecimals(value) === value,
    "Use at most 2 decimal places",
  );

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
  mrp: optionalMoneySchema,
  sellingPrice: optionalMoneySchema,
  sellingCode: z.string().trim().max(50, "Selling code is too long"),
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

/** Raw form input before Zod transforms (what React Hook Form holds). */
export type PurchaseFormInput = z.input<typeof purchaseSchema>;

/** One line of `PurchaseFormValues.items`. */
export type PurchaseLineFormValues = PurchaseFormValues["items"][number];

/** One raw input line (what React Hook Form holds before transforms). */
export type PurchaseLineFormInput = PurchaseFormInput["items"][number];

/**
 * Blank line for "Add row": quantity starts at 1 (the usual restock) and the
 * cost is `NaN`, which the number input renders empty — a forgotten cost then
 * fails validation as "Required" instead of silently ordering for free.
 * MRP and selling price start as `NaN` (rendered empty, validated as absent)
 * and the selling code starts empty — never prefilled from the global product.
 * The `satisfies` keeps the blank assignable to the schema *input* (where
 * `NaN` is allowed) while the inferred values type stays `number | null`.
 */
export const EMPTY_PURCHASE_LINE = {
  productId: 0,
  quantity: 1,
  unitCost: Number.NaN,
  discount: 0,
  mrp: Number.NaN,
  sellingPrice: Number.NaN,
  sellingCode: "",
} satisfies z.input<typeof lineSchema>;

/** Blank state for "New purchase": one line, no tax, unpaid. */
export const EMPTY_PURCHASE_VALUES: z.input<typeof purchaseSchema> = {
  supplierId: 0,
  taxRate: 0,
  paidAmount: 0,
  note: "",
  items: [{ ...EMPTY_PURCHASE_LINE }],
};