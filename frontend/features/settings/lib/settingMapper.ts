import type { SettingPayload } from "@/types/setting";

/**
 * `SettingsResource` collection → the frontend's settings map.
 *
 * `SettingsService::all()` returns a collection of `BusinessSetting` models and
 * `SettingsResource` exposes only `key` and `value`, so this mapper is a fold
 * rather than a field-by-field rename: there is no snake_case here, and inventing
 * one would be a lie about the contract.
 *
 * Two behaviours are deliberate:
 *
 *   - **Value passthrough.** `value` is a `text` column that may legitimately
 *     hold `null` (a row written with an explicit JSON `null`) or any string.
 *     Numeric-looking values such as `"5.00"` stay strings: passing them through
 *     `Number()` would risk a float round-trip on configuration the backend
 *     stores verbatim, and the UI edits them as text.
 *   - **Order is the server's.** `SettingsService::all()` orders by `key`, and
 *     that order is preserved by `Object.entries`, so the screen renders the
 *     API's own ordering.
 *
 * Last-write-wins on a duplicated key keeps the fold total; a duplicate cannot
 * occur because `(business_id, key)` carries a unique index.
 */
export function toSettings(payloads: readonly SettingPayload[]): Record<string, string | null> {
  const settings: Record<string, string | null> = {};

  for (const payload of payloads) {
    settings[payload.key] = payload.value;
  }

  return settings;
}
