import { z } from "zod";

import type { ApiPaymentMethod } from "@/features/sales/services/salesService";

/**
 * Form contract of the Record Payment dialog.
 *
 * `POST /api/v1/sales/{sale}/payments` (`StorePaymentRequest`) accepts:
 *
 *   amount          required, numeric, > 0, ≤ Money::MAX, at most 2 decimals
 *   payment_method  required, one of Payment::PAYMENT_METHODS
 *   reference       nullable string, max 100
 *   notes           nullable string, max 2000
 *
 * The form is typed in the **API's** vocabulary (`ApiPaymentMethod`) rather
 * than the UI's, because a payment is only ever created here: the closed set
 * `Payment::PAYMENT_METHODS` validates is exactly what the dialog offers, so no
 * translation step (and no `toApiPaymentMethod` call) can introduce a value the
 * backend would reject with a 422. The richer POS vocabulary
 * (`mobile_banking`, `store_credit`, …) stays a checkout concern.
 *
 * `createSalePaymentSchema` closes over the invoice's *canonical* due amount —
 * `Sale.dueAmount`, which `SaleResource` returns as `due_amount`. It never
 * recomputes `grandTotal - paidAmount`, so the dialog's cap and the screen's
 * figure can never disagree, and the API re-checks the same limit against the
 * locked row.
 */

const paymentMethodSchema = z.enum(
  ["cash", "card", "bank", "cheque", "due"],
  { invalid_type_error: "Select a payment method" },
);

const paymentFields = {
  amount: z.number({
    invalid_type_error: "Enter an amount",
    required_error: "Enter an amount",
  }),
  paymentMethod: paymentMethodSchema,
  reference: z.string().trim().max(100, "Reference is too long"),
  notes: z.string().trim().max(2000, "Notes are too long"),
};

/** Rounds to two decimals, mirroring the API's `decimal:0,2` rule. */
function toTwoDecimals(value: number): number {
  return Math.round(value * 100) / 100;
}

/**
 * Valid shape of the payment form plus the two rules the cashier must not be
 * stopped by: an amount above zero, and never more than what is still due.
 */
export function createSalePaymentSchema(dueAmount: number) {
  return z.object(paymentFields).superRefine((values, ctx) => {
    if (!Number.isFinite(values.amount) || values.amount <= 0) {
      ctx.addIssue({ code: "custom", message: "Must be more than 0", path: ["amount"] });
      return;
    }

    // `StorePaymentRequest` enforces `decimal:0,2`; catching it here keeps the
    // message actionable instead of surfacing a raw field error from the API.
    if (toTwoDecimals(values.amount) !== values.amount) {
      ctx.addIssue({
        code: "custom",
        message: "Use at most 2 decimal places",
        path: ["amount"],
      });
      return;
    }

    // A float-safe epsilon, so paying the exact remaining amount is never
    // rejected for a representation artefact.
    if (values.amount > dueAmount + 0.001) {
      ctx.addIssue({
        code: "custom",
        message: `Only ${dueAmount} is still due`,
        path: ["amount"],
      });
    }
  });
}

export type SalePaymentFormValues = z.infer<ReturnType<typeof createSalePaymentSchema>>;

/**
 * Opening state of the dialog: the full remaining amount and cash.
 *
 * Defaulting to the whole outstanding balance makes the common case — "the
 * customer settles up" — a single click, while `cash` is the method the
 * cashier collects in almost every case. Both are still freely editable.
 */
export function buildSalePaymentValues(dueAmount: number): SalePaymentFormValues {
  return {
    amount: toTwoDecimals(dueAmount),
    paymentMethod: "cash",
    reference: "",
    notes: "",
  };
}

/** The payment method options the dialog offers, in `Payment::PAYMENT_METHODS` order. */
export const SALE_PAYMENT_METHOD_OPTIONS: readonly {
  value: ApiPaymentMethod;
  label: string;
}[] = [
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "bank", label: "Bank transfer" },
  { value: "cheque", label: "Cheque" },
  { value: "due", label: "Due" },
];

/**
 * Narrows a `<Select>` value onto the closed API set.
 *
 * base-ui reports the picked item as a plain `string`, so the value has to be
 * checked against the same list the options are rendered from rather than
 * cast — a value that is not in `SALE_PAYMENT_METHOD_OPTIONS` is not something
 * the API would accept, and must never reach `setValue`.
 */
export function toApiPaymentMethodOption(value: string): ApiPaymentMethod | null {
  return (
    SALE_PAYMENT_METHOD_OPTIONS.find((option) => option.value === value)?.value ?? null
  );
}