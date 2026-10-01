/**
 * Presentation catalogue for the free-form settings map.
 *
 * The backend has no settings *columns*: `business_settings` is a
 * `(business_id, key, value)` key/value store, and the API accepts any key
 * matching `^[\p{L}\p{N}][\p{L}\p{N}\._\-: ]*$` (≤ 100 chars) with a string/null
 * value (≤ 2000 chars). So the field list below is a **frontend convention** over
 * that store — the labels, grouping and input types live here, and the keys are
 * just the names this app happens to use. Nothing in the backend knows them, and
 * adding a row here changes no migration and no API.
 *
 * This is why the screen also renders "other" keys: a setting written by a
 * seeder, an admin script or another client still belongs to this business and
 * must stay visible and editable, so an unknown key is never dropped.
 */

/** How a setting key is edited. */
export type SettingFieldKind = "text" | "textarea" | "select" | "number";

/** One entry of the settings form. */
export interface SettingField {
  /** The key persisted in `business_settings`. */
  key: string;
  /** Human label shown above the input. */
  label: string;
  /** Help text shown under the input. */
  hint: string;
  kind: SettingFieldKind;
  /** Options for a `select` field. */
  options?: readonly string[];
  /**
   * Placeholder shown while the key has no stored value.
   *
   * Display-only: it is never submitted and never written over an existing
   * server value — an unset setting submits as `null`, not as its placeholder.
   */
  placeholder?: string;
}

/** Groups rendered as separate cards, in this order. */
export interface SettingGroup {
  title: string;
  description: string;
  fields: readonly SettingField[];
}


/**
 * The settings this UI knows how to present.
 *
 * Ordered as they appear on the page. Every value is plain text: the `value`
 * column is `text` and the API validates `nullable|string`, so numbers are
 * entered and stored as their decimal text (e.g. `"5.00"`) rather than parsed
 * into a JavaScript float that could round a configuration value.
 */
export const SETTING_GROUPS: readonly SettingGroup[] = [
  {
    title: "Business profile",
    description: "Printed on receipts and shown to staff.",
    fields: [
      {
        key: "business_name",
        label: "Business name",
        hint: "Appears at the top of every receipt.",
        kind: "text",
        placeholder: "Your business name",
      },
      {
        key: "business_address",
        label: "Address",
        hint: "Postal address printed on receipts.",
        kind: "textarea",
        placeholder: "Street, city, postcode",
      },
      {
        key: "business_phone",
        label: "Phone",
        hint: "Contact number printed on receipts.",
        kind: "text",
        placeholder: "+880 1700-000000",
      },
      {
        key: "business_email",
        label: "Email",
        hint: "Contact address printed on receipts.",
        kind: "text",
        placeholder: "hello@example.com",
      },
    ],
  },
  {
    title: "Locale",
    description: "How money and dates are presented.",
    fields: [
      {
        key: "currency",
        label: "Currency code",
        hint: "ISO 4217 code, e.g. BDT or USD.",
        kind: "text",
        placeholder: "BDT",
      },
      {
        key: "currency_symbol",
        label: "Currency symbol",
        hint: "Symbol used next to amounts on receipts.",
        kind: "text",
        placeholder: "৳",
      },
      {
        key: "timezone",
        label: "Timezone",
        hint: "IANA name, e.g. Asia/Dhaka. Stored verbatim; the app never converts it.",
        kind: "text",
        placeholder: "Asia/Dhaka",
      },
    ],
  },

  {
    title: "Tax",
    description: "Default rate applied to new sales.",
    fields: [
      {
        key: "tax_enabled",
        label: "Apply tax",
        hint: '"1" or "0".',
        kind: "select",
        options: ["1", "0"],
      },
      {
        key: "tax_rate",
        label: "Tax rate (%)",
        hint: "Decimal text, e.g. 5.00. Stored exactly as typed.",
        kind: "number",
        placeholder: "0.00",
      },
      {
        key: "tax_label",
        label: "Tax label",
        hint: "Name printed beside the tax line, e.g. VAT.",
        kind: "text",
        placeholder: "VAT",
      },
    ],
  },
  {
    title: "Receipt",
    description: "Invoice numbering and the printed message.",
    fields: [
      {
        key: "invoice_prefix",
        label: "Invoice prefix",
        hint: "Prepended to generated invoice numbers.",
        kind: "text",
        placeholder: "INV",
      },
      {
        key: "receipt_header",
        label: "Receipt header",
        hint: "Printed above the line items.",
        kind: "textarea",
        placeholder: "Welcome!",
      },
      {
        key: "receipt_footer",
        label: "Receipt footer",
        hint: "Printed at the bottom, e.g. a thank-you line.",
        kind: "textarea",
        placeholder: "Thank you for shopping with us!",
      },
    ],
  },
];

/** Every key the form presents, flattened. */
export const SETTING_FIELDS: readonly SettingField[] = SETTING_GROUPS.flatMap(
  (group) => group.fields,
);

/** The known keys, for a fast lookup while building the form. */
const FIELD_BY_KEY: ReadonlyMap<string, SettingField> = new Map(
  SETTING_FIELDS.map((field) => [field.key, field]),
);

/** The settings field for a known key, or `null` for an unrecognised key. */
export function fieldForKey(key: string): SettingField | null {
  return FIELD_BY_KEY.get(key) ?? null;
}

/** `true` when the form presents this key. */
export function isKnownSettingKey(key: string): boolean {
  return FIELD_BY_KEY.has(key);
}

/**
 * The form's row state: one entry per key the screen renders.
 *
 * `value` is the *string* to display, so a stored `null` and a stored `""` look
 * identical in an input — which is why the save maps an emptied text field back
 * to `null` rather than writing an empty string.
 */
export type SettingsFormValues = Record<string, string>;

/** The API's own limits (`BusinessSetting::MAX_KEY_LENGTH` / `MAX_VALUE_LENGTH`). */
export const MAX_KEY_LENGTH = 100;
export const MAX_VALUE_LENGTH = 2000;

/**
 * Key format accepted by `UpdateSettingsRequest::isValidKey()`.
 *
 * Mirrored so the "add setting" input can reject an unusable key before a round
 * trip and match the server's rule exactly. The API stays the authority: a `422`
 * still replaces whatever the local check said.
 */
export const SETTING_KEY_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N}._\-: ]*$/u;

/**
 * Keys the API refuses to write (`SettingsService::RESERVED_KEYS`).
 *
 * `business_id` is here because it is tenancy plumbing: the client never chooses
 * the owning business, and offering it as a setting would be a lie the API
 * rejects with a `422` anyway.
 */
export const RESERVED_SETTING_KEYS: readonly string[] = [
  "business_id",
  "id",
  "key",
  "value",
  "created_at",
  "updated_at",
];

/**
 * Credential-shaped keys the API refuses to write (`SettingsService`).
 *
 * `SettingsService::all()` also filters these out of every read, so one can never
 * reach the form in the first place and the client never needs to mask a value.
 */
export const SENSITIVE_KEY_FRAGMENTS: readonly string[] = [
  "secret",
  "password",
  "passwd",
  "token",
  "api_key",
  "apikey",
  "private_key",
  "credential",
  "signature",
];

/** `true` when a key is reserved or credential-shaped, i.e. never writable. */
export function isProtectedSettingKey(key: string): boolean {
  const normalised = key.toLowerCase();

  if (RESERVED_SETTING_KEYS.includes(normalised)) {
    return true;
  }
  return SENSITIVE_KEY_FRAGMENTS.some((fragment) => normalised.includes(fragment));
}

/** Renders a snake_case key as a readable label, e.g. `receipt_footer` → "Receipt footer". */
export function labelForKey(key: string): string {
  const words = key.replace(/[._\-:]+/g, " ").trim();
  if (words === "") {
    return key;
  }
  return words.charAt(0).toUpperCase() + words.slice(1);
}
