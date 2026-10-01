/**
 * Money and quantity formatting for the POS.
 *
 * A fixed number formatter plus an explicit symbol is used instead of
 * `Intl.NumberFormat` currency styles so output is identical on server and
 * client (no hydration mismatch) and always shows two decimals for the
 * scan-and-verify reading cashiers do at the till.
 *
 * The symbol is a *parameter* with a project-wide default rather than a
 * constant baked into the formatter: most screens keep the default, while the
 * receipt passes the symbol the business configured in Settings
 * (`currency_symbol`, falling back to the `currency` code). The amount itself
 * is never re-derived here — callers pass the figure the API returned.
 */

/** Taka, the project's default currency symbol. */
export const DEFAULT_CURRENCY_SYMBOL = "৳";

const moneyFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * Formats an amount with a currency symbol, e.g. `৳1,234.50`.
 *
 * A blank symbol falls back to {@link DEFAULT_CURRENCY_SYMBOL} rather than
 * printing a bare number, and non finite input becomes `0`.
 */
export function formatMoney(amount: number, symbol: string = DEFAULT_CURRENCY_SYMBOL): string {
  const safeAmount = Number.isFinite(amount) ? amount : 0;
  const sign = symbol.trim() === "" ? DEFAULT_CURRENCY_SYMBOL : symbol;
  return `${sign}${moneyFormatter.format(safeAmount)}`;
}

/** Formats a quantity without trailing zeros: `2` stays `2`, `0.5` stays `0.5`. */
export function formatQuantity(quantity: number): string {
  if (!Number.isFinite(quantity)) {
    return "0";
  }

  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(2);
}

/** Parses typed money input; empty, invalid or negative input becomes `0`. */
export function parseAmount(raw: string): number {
  const value = Number(raw.trim());

  return Number.isFinite(value) && value > 0 ? value : 0;
}
