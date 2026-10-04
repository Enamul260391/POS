import { z } from "zod";

/**
 * Form contracts for the cash drawer dialogs.
 *
 * One schema per dialog, mirroring the matching Laravel form request so the
 * cashier is corrected before a round trip rather than after it:
 *
 *   open   `OpenCashRegisterRequest`    — `opening_balance` required, ≥ 0
 *   close  `CloseCashRegisterRequest`   — `closing_balance` required, ≥ 0
 *   move   `CashRegisterTransactionRequest` — `amount` required, > 0
 *
 * The ceiling is `Money::MAX` (`9999999999999.99`), the exact bound the API
 * validates against, so the client can never reject an amount the server would
 * accept. The note limit likewise matches the API's `max:2000`. Money arrives
 * as a number through `valueAsNumber`.
 *
 * These schemas are a UX layer only. The backend remains the final authority on
 * every rule, including the ones a client cannot check (a register already open,
 * a register already closed, ownership).
 */

/** `App\Support\Money::MAX`, the largest amount the API accepts. */
const MAX_AMOUNT = 9_999_999_999_999.99;

export const openRegisterSchema = z.object({
  openingBalance: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative")
    .max(MAX_AMOUNT, "Amount is too large"),
  notes: z.string().trim().max(2000, "Note is too long"),
});

export type OpenRegisterFormValues = z.infer<typeof openRegisterSchema>;

export const cashMoveSchema = z.object({
  amount: z
    .number({ invalid_type_error: "Required" })
    .positive("Must be more than 0")
    .max(MAX_AMOUNT, "Amount is too large"),
  note: z.string().trim().max(2000, "Note is too long"),
});

export type CashMoveFormValues = z.infer<typeof cashMoveSchema>;

/**
 * The manual cash-movement amendment form.
 *
 * The same three fields `cashMoveSchema` accepts, with the same bounds, because
 * they are the only ones the API will amend (`UpdateCashRegisterTransactionRequest`).
 * The note is the only field allowed to be empty, and the type is constrained to
 * the two manual kinds so the form cannot express a request the API will reject
 * — in particular it can never turn a manual movement into a sale or expense.
 */
export const cashMoveEditSchema = z.object({
  type: z.enum(["cash_in", "cash_out"]),
  amount: z
    .number({ invalid_type_error: "Required" })
    .positive("Must be more than 0")
    .max(MAX_AMOUNT, "Amount is too large"),
  note: z.string().trim().max(2000, "Note is too long"),
});

export type CashMoveEditFormValues = z.infer<typeof cashMoveEditSchema>;

export const closeRegisterSchema = z.object({
  closingBalance: z
    .number({ invalid_type_error: "Required" })
    .min(0, "Cannot be negative")
    .max(MAX_AMOUNT, "Amount is too large"),
  notes: z.string().trim().max(2000, "Note is too long"),
});

export type CloseRegisterFormValues = z.infer<typeof closeRegisterSchema>;

export const EMPTY_CASH_MOVE_VALUES: CashMoveFormValues = {
  amount: Number.NaN,
  note: "",
};
