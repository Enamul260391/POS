/**
 * The business's printed identity, read from Settings.
 *
 * `GET /api/v1/settings` is a free-form key/value store, so the receipt cannot
 * type-cast a payload: it asks for the keys it prints (`business_name`,
 * `business_address`, `business_phone`, `business_email`, `currency`,
 * `currency_symbol`, `receipt_header`, `receipt_footer`) and this module turns
 * the map into a value the receipt can render.
 *
 * Two rules govern the mapping:
 *
 *   - **Nothing is invented.** A key that is absent, `null` or blank becomes an
 *     empty string, and the receipt simply omits that line. There is no
 *     placeholder shop name, address, phone or thank-you line: a receipt that
 *     says "MOCK SHOP" is worse than one that prints only what the database
 *     actually holds.
 *   - **The receipt never writes.** This is read-only; the Settings screen owns
 *     every `PATCH /settings` call.
 *
 * Only presentation metadata comes from here. Invoice numbers, totals, lines,
 * payments and the cashier all stay sourced from the persisted sale — Settings
 * is never allowed to override a stored figure.
 */

import { DEFAULT_CURRENCY_SYMBOL } from "@/features/pos/lib/format";
import type { BusinessSettings } from "@/types/setting";

/** The shop block, the printed messages and the currency symbol. */
export interface ReceiptBusinessSettings {
  /** `business_name` — the bold line at the top. */
  name: string;
  /** `business_address` — one or more printed lines. */
  address: string;
  /** `business_phone` — printed with a `Phone:` label. */
  phone: string;
  /** `business_email` — printed with an `Email:` label. */
  email: string;
  /** `receipt_header` — optional message above the line items. */
  header: string;
  /** `receipt_footer` — optional message below the totals. */
  footer: string;
  /**
   * Symbol used to print amounts.
   *
   * `currency_symbol` wins; an unset symbol falls back to the `currency` ISO
   * code (a poor man's `USD` prefix, but an honest one), and with neither the
   * project's own default is used so an amount is never printed bare.
   */
  currencySymbol: string;
}

/**
 * What the receipt knows about the business before (or instead of) a successful
 * Settings read.
 *
 * Every field empty: this is deliberately the "print nothing" state rather than
 * a set of stand-in values, so a failed or in-flight request can never put fake
 * business information on paper.
 */
export const EMPTY_RECEIPT_SETTINGS: ReceiptBusinessSettings = {
  name: "",
  address: "",
  phone: "",
  email: "",
  header: "",
  footer: "",
  currencySymbol: DEFAULT_CURRENCY_SYMBOL,
};

/** A stored value trimmed for display, or `""` when unset/blank. */
function settingText(settings: BusinessSettings, key: string): string {
  return settings[key]?.trim() ?? "";
}

/**
 * The settings map (`toSettings`'s shape) → the receipt's business block.
 *
 * A `null` map — the state while the first request is in flight, or after one
 * failed — maps to {@link EMPTY_RECEIPT_SETTINGS}, which is what keeps a receipt
 * printable (with its real sale data) even when Settings are unavailable.
 */
export function toReceiptSettings(
  settings: BusinessSettings | null | undefined,
): ReceiptBusinessSettings {
  if (settings === null || settings === undefined) {
    return EMPTY_RECEIPT_SETTINGS;
  }

  return {
    name: settingText(settings, "business_name"),
    address: settingText(settings, "business_address"),
    phone: settingText(settings, "business_phone"),
    email: settingText(settings, "business_email"),
    header: settingText(settings, "receipt_header"),
    footer: settingText(settings, "receipt_footer"),
    currencySymbol:
      settingText(settings, "currency_symbol") ||
      settingText(settings, "currency") ||
      DEFAULT_CURRENCY_SYMBOL,
  };
}