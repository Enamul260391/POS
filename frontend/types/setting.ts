/**
 * Business settings — the `business_settings` key/value store.
 *
 * The backend models settings as a **free-form map**, not a fixed column set:
 * `SettingsResource` emits one `{ key, value }` pair per stored row, and
 * `UpdateSettingsRequest` accepts a flat `"{<key>": "<value>"}` body. This file
 * therefore describes a *map*, and deliberately declares no named business
 * fields — there is no `currency` column, no `business_name` column, and nothing
 * in the API response that would let the frontend assert one. The UI's friendly
 * field labels (see `@/features/settings/lib/settings`) are presentation only.
 *
 * Tenancy is absent by design. `SettingsController` reads the owning business
 * from the authenticated user and never from the payload, so no type here has a
 * `businessId`, and the API refuses `business_id` as a reserved key.
 *
 * Values are **strings or null**, never numbers, booleans or dates. `value` is a
 * plain `text` column, and `UpdateSettingsRequest` validates every entry with
 * `nullable|string|max:2000` — so a JSON number or boolean is a `422`, not
 * something the mapper should quietly coerce.
 */

/** One stored setting, as the frontend domain type. */
export interface SettingEntry {
  /** The stored key, verbatim — e.g. `currency`, `receipt_footer`. */
  key: string;
  /**
   * The stored value, verbatim.
   *
   * `null` is a real stored state (a row written with an explicit JSON `null`),
   * so it is preserved rather than collapsed to `""`.
   */
  value: string | null;
}

/**
 * One `SettingsResource` item.
 *
 * Already camelCase-neutral: both keys (`key`, `value`) are single lowercase
 * words, so no snake_case → camelCase translation applies to this resource.
 */
export interface SettingPayload {
  key: string;
  value: string | null;
}

/** The business's settings as a domain map, keyed by setting key. */
export type BusinessSettings = Record<string, string | null>;

/**
 * Body of `PUT|PATCH /settings` (`UpdateSettingsRequest`).
 *
 * A flat map — *not* `{ settings: { … } }`. Laravel reads the body root, so
 * `PUT {"receipt_footer": "Thanks"}` is the natural call.
 */
export type SettingsUpdateInput = Record<string, string | null>;
