/**
 * Payment captured against a sale.
 *
 * A sale can be settled with several payments (for example part cash, part
 * card), so payments are a separate collection that is nested inside a `Sale`
 * response. This mirrors the Laravel relation: sale `hasMany` payments.
 */

/**
 * How the payment was collected.
 * - `cash`           counter cash (the only method posted to the cash drawer)
 * - `card`           debit/credit card terminal
 * - `mobile_banking` mobile financial service (bKash, Nagad, Rocket, ...)
 * - `bank`           direct bank transfer (API name: `bank`)
 * - `bank_transfer`  legacy alias kept for the purchase mocks
 * - `cheque`         bank cheque
 * - `store_credit`   settled from the customer's advance/credit balance
 * - `due`            the balance is carried as a receivable
 *
 * Phase 25: `bank` and `due` were added because `Payment::PAYMENT_METHODS`
 * accepts them; `mobile_banking` and `store_credit` are UI-only values that are
 * mapped onto a real API method before a request is sent (see
 * `features/pos/lib/paymentMapping.ts`).
 */
export type PaymentMethod =
  | "cash"
  | "card"
  | "mobile_banking"
  | "bank"
  | "bank_transfer"
  | "cheque"
  | "store_credit"
  | "due";

/**
 * State of a single payment.
 * - `pending`   recorded but not confirmed yet
 * - `completed` money received
 * - `failed`    attempt failed and must be retried
 * - `refunded`  money returned to the customer
 */
export type PaymentStatus = "pending" | "completed" | "failed" | "refunded";

export interface Payment {
  /** Primary key. */
  id: number;
  /** Id of the sale this payment belongs to. */
  saleId: number;
  /** Human readable reference, e.g. "PAY-2026-000042". */
  reference: string;
  /** How the money was collected. */
  method: PaymentMethod;
  /** Current state of the payment. */
  status: PaymentStatus;
  /** Amount collected through this payment. */
  amount: number;
  /** Provider name for non cash methods, e.g. "bKash", "Visa". */
  provider: string | null;
  /** Provider transaction id or approval code for non cash methods. */
  transactionId: string | null;
  /** Cash handed over by the customer (cash payments only), else `null`. */
  tenderedAmount: number | null;
  /** Cash returned to the customer (cash payments only), else `null`. */
  changeAmount: number | null;
  /** ISO 8601 timestamp of when the payment was captured. */
  paidAt: string;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Raw `PaymentResource` payload.
 *
 * The API stores far less than the UI shows: only `payment_method`, `amount`,
 * `reference` and `paid_at` exist, so `status` is always `completed` on the
 * client side, and `provider`, `transactionId`, `tenderedAmount` and
 * `changeAmount` are `null` for a payment that came from the API. The POS
 * change is a cash-handling figure the cashier entered; it is displayed from
 * the checkout response and never persisted.
 */
export interface PaymentPayload {
  id: number;
  sale_id: number | null;
  purchase_id: number | null;
  payment_method: string;
  amount: string;
  reference: string | null;
  paid_at: string | null;
  received_by: number | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}
