import { z } from "zod";

import { DEFAULT_RETURN_REASON } from "@/features/sales/lib/returns";
import type { Sale } from "@/types/sale";
import type { SaleReturnReason } from "@/types/saleReturn";

/**
 * Form contract of the Return dialog (Phase 10).
 *
 * `createSaleReturnSchema` closes over how much of each invoice line may still
 * come back, so an over-return is a validation error in the form and the API
 * protects the same limit again against the stored invoice.
 *
 * The form holds one row per invoice line; `returned` is the "select returned
 * items" tick and a row with `returned: false` is simply ignored. Quantities
 * are prefilled with what is still returnable, which makes the common case —
 * "give all of this back" — a single click.
 *
 * The form does not carry a refund method. `POST /sale-returns` accepts an
 * optional `refunds` split, but omitting it is not a gap: the API allocates the
 * refund against what the sale actually collected, capped per method, and
 * records the result. Asking the cashier to re-enter that would be a chance to
 * disagree with the invoice it is derived from.
 *
 * `restock` stays on the form because the refund totals use it, and it is always
 * `true`: the API restores stock for every returned line that tracks it.
 */

/** Reasons the form accepts (all of `SaleReturnReason`). */
const REASON_VALUES = [
  "damaged",
  "expired",
  "wrong_item",
  "not_as_described",
  "customer_changed_mind",
  "other",
] as const satisfies readonly SaleReturnReason[];

const returnLineSchema = z.object({
  saleItemId: z.number().int().min(1),
  /** Whether this invoice line comes back at all. */
  returned: z.boolean(),
  quantity: z.number({ invalid_type_error: "Required" }).min(0, "Cannot be negative"),
  reason: z.enum(REASON_VALUES),
  /** Always `true`: the API restocks every returned line. */
  restock: z.boolean(),
});

const returnFields = {
  lines: z.array(returnLineSchema).min(1, "This invoice has no lines to return"),
  note: z.string().trim().max(500, "Notes are too long"),
};

/**
 * Valid shape of the return form plus the cross-line rules: at least one
 * selected item, and never more than what is still returnable per line.
 */
export function createSaleReturnSchema(
  returnableByItemId: ReadonlyMap<number, number>,
) {
  return z.object(returnFields).superRefine((values, ctx) => {
    let selected = 0;

    values.lines.forEach((line, index) => {
      if (!line.returned) {
        return;
      }
      selected += 1;

      const returnable = returnableByItemId.get(line.saleItemId) ?? 0;
      if (!Number.isFinite(line.quantity)) {
        ctx.addIssue({ code: "custom", message: "Required", path: ["lines", index, "quantity"] });
      } else if (line.quantity <= 0) {
        ctx.addIssue({
          code: "custom",
          message: "Must be more than 0",
          path: ["lines", index, "quantity"],
        });
      } else if (line.quantity > returnable + 0.001) {
        ctx.addIssue({
          code: "custom",
          message:
            returnable <= 0
              ? "This line has already been fully returned"
              : `Only ${returnable} can still be returned`,
          path: ["lines", index, "quantity"],
        });
      }
    });

    if (selected === 0) {
      ctx.addIssue({
        code: "custom",
        message: "Select at least one item to return",
        path: ["lines"],
      });
    }
  });
}

export type SaleReturnFormValues = z.infer<ReturnType<typeof createSaleReturnSchema>>;

/** One row of `SaleReturnFormValues.lines`. */
export type SaleReturnLineFormValues = SaleReturnFormValues["lines"][number];

/**
 * Blank state for the return of one invoice: nothing ticked, quantities
 * prefilled with what is still returnable.
 *
 * `restock` is `true` for every row because `SaleReturnService` reverses the
 * stock of each returned line; the dialog no longer lets a cashier set a flag
 * the API would ignore.
 */
export function buildSaleReturnValues(
  sale: Sale,
  returnableByItemId: ReadonlyMap<number, number>,
): SaleReturnFormValues {
  return {
    lines: sale.items.map((item) => {
      const returnable = returnableByItemId.get(item.id) ?? 0;
      return {
        saleItemId: item.id,
        returned: false,
        quantity: returnable,
        reason: DEFAULT_RETURN_REASON,
        restock: true,
      };
    }),
    note: "",
  };
}
