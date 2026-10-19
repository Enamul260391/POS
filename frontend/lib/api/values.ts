/**
 * Conversion helpers between Laravel's serialised values and the frontend's
 * domain types.
 *
 * The API speaks two dialects the UI does not:
 *
 *   money     Laravel casts money columns to `decimal:2` and serialises them as
 *             **strings** (`"1234.50"`) on purpose, so no cent is lost to a
 *             float. The UI does arithmetic and formatting in `number`, so
 *             every mapper goes through `toMoney` here rather than parsing
 *             inline.
 *   quantity  The ledger is `decimal(15,4)` and is normalised to a clean string
 *             (`"5"`, `"2.5"`), never a JS float.
 *
 * Formatting (`formatMoney`) stays a *display* concern in
 * `@/features/pos/lib/format`; this module only handles the wire format.
 */

/** Parses a decimal string into a number, rounding to two decimals. */
export function toMoney(value: string | number | null | undefined): number {
  if (value === null || value === undefined) {
    return 0;
  }
  const parsed = typeof value === "number" ? value : Number(value);
  if (!Number.isFinite(parsed)) {
    return 0;
  }
  // `Math.round(x * 100) / 100` is safe here: the value arrived as a decimal
  // *string* from the API, so the multiplication is on an already-exact decimal,
  // and the result is only ever used for display and further client math — the
  // persisted total always comes back from Laravel.
  return Math.round(parsed * 100) / 100;
}

/**
 * Parses an optional decimal into a number, preserving absence as `null`.
 *
 * Unlike `toMoney` (which maps missing to `0` for totals that must always
 * render), an absent MRP / selling price must stay `null` so historic rows
 * and untouched fields never read as a free `0` price or `NaN` in a total.
 */
export function toNullableMoney(
  value: string | number | null | undefined,
): number | null {
  if (value === null || value === undefined) {
    return null;
  }
  const text = typeof value === "number" ? String(value) : value.trim();
  if (text === "") {
    return null;
  }
  const parsed = Number(text);
  if (!Number.isFinite(parsed)) {
    return null;
  }
  return Math.round(parsed * 100) / 100;
}

/** Parses a ledger quantity into a number, rounding to four decimals. */
export function toQuantity(value: string | number | null | undefined): number {
  if (value === null || value === undefined) {
    return 0;
  }
  const parsed = typeof value === "number" ? value : Number(value);
  return Number.isFinite(parsed) ? Math.round(parsed * 10_000) / 10_000 : 0;
}

/** `true` for a non-empty string, after trimming. */
export function toText(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

/**
 * Reads a value from an API payload, defaulting when the key is absent.
 *
 * Laravel's `whenLoaded` / `whenCounted` conditionally omit keys, so a mapper
 * that assumes presence would produce `undefined` in a plain object. Funnelling
 * every read through this keeps the "missing means empty" rule in one place.
 */
export function readField<T>(
  source: Record<string, unknown>,
  key: string,
  fallback: T,
): T {
  const value = source[key];
  return value === undefined || value === null ? fallback : (value as T);
}

/** `YYYY-MM-DD` for "today" in the browser's own time zone. */
export function todayDateString(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}