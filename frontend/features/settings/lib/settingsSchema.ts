import {
  isKnownSettingKey,
  labelForKey,
  MAX_KEY_LENGTH,
  MAX_VALUE_LENGTH,
  SETTING_FIELDS,
  SETTING_KEY_PATTERN,
  isProtectedSettingKey,
  type SettingField,
  type SettingsFormValues,
} from "@/features/settings/lib/settings";
import type { ApiValidationErrors } from "@/types/api";

/**
 * Form contract of the settings screen.
 *
 * The backend stores settings as a free-form key/value map with no column-level
 * validation beyond `nullable|string|max:2000` and the key format check, so this
 * schema validates the two things the API actually rejects — an unusable key and
 * an over-long key/value — and nothing else. It deliberately does **not**
 * second-guess the value of `currency` or `tax_rate`: Laravel stores whatever
 * string it is given, so a local rule like "must be a 3-letter code" would reject
 * input the API would happily persist.
 *
 * The API remains the authority. These checks catch an obviously malformed entry
 * before a round trip, and a `422` from the server always replaces whatever they
 * said.
 */

/** A key the form can submit, once it has passed the local checks. */
export interface SettingsPatch {
  [key: string]: string | null;
}

/** Per-key error messages, keyed by the setting key (never camelCased). */
export type SettingsFieldErrors = Record<string, string>;

/** Validates one key/value pair; returns an error message, or `null` when valid. */
function validatePair(key: string, value: string): string | null {
  if (key === "") {
    return "Key is required";
  }
  if (key.length > MAX_KEY_LENGTH) {
    return "Key is too long";
  }
  if (!SETTING_KEY_PATTERN.test(key)) {
    return "Key may only contain letters, numbers, spaces and . _ - :";
  }
  if (isProtectedSettingKey(key)) {
    return "This key is reserved and cannot be modified";
  }
  if (value.length > MAX_VALUE_LENGTH) {
    return `Value must be at most ${MAX_VALUE_LENGTH} characters`;
  }
  return null;
}

/**
 * Validates every entry of the form.
 *
 * Returns `null` when the whole batch is submittable. An empty batch is invalid:
 * `UpdateSettingsRequest::withValidator()` answers `{}` with "At least one
 * setting must be provided.", so the screen never sends one.
 */
export function validateSettingsForm(values: SettingsFormValues): SettingsFieldErrors | null {
  const errors: SettingsFieldErrors = {};

  for (const [key, value] of Object.entries(values)) {
    const message = validatePair(key, value);
    if (message !== null) {
      errors[key] = message;
    }
  }

  return Object.keys(errors).length > 0 ? errors : null;
}

/**
 * Reduces the whole form to the keys that actually changed.
 *
 * `PATCH /settings` upserts only the keys it receives, so sending only the diff
 * is what keeps an edit here from overwriting a value someone else changed in a
 * key this form does not show. Values are compared as raw strings: the server
 * stores text verbatim, so `"5"` and `"5.0"` are genuinely different settings and
 * must not be collapsed by a numeric comparison.
 *
 * An emptied field submits `null` rather than `""`. The API accepts both, but
 * `null` is the one state the screen also renders as "unset", so clearing a
 * field and reloading shows it empty rather than as an invisible empty string.
 */
export function toSettingsPatch(
  values: SettingsFormValues,
  original: Record<string, string | null>,
): SettingsPatch {
  const patch: SettingsPatch = {};

  for (const [key, value] of Object.entries(values)) {
    const next = value === "" ? null : value;
    if (original[key] !== next) {
      patch[key] = next;
    }
  }

  return patch;
}

/** `true` when there is at least one key to send. */
export function hasPendingChanges(patch: SettingsPatch): boolean {
  return Object.keys(patch).length > 0;
}

/** Seeds the form from the API response, in the catalogue's declared order. */
export function toSettingsFormValues(settings: Record<string, string | null>): SettingsFormValues {
  const values: SettingsFormValues = {};

  for (const field of SETTING_FIELDS) {
    values[field.key] = settings[field.key] ?? "";
  }
  // Keys the backend holds that this UI has no field for. They are rendered
  // read/write in an "other settings" section rather than dropped, so a value
  // written by a seeder or another client stays visible and editable.
  for (const [key, value] of Object.entries(settings)) {
    if (!isKnownSettingKey(key)) {
      values[key] = value ?? "";
    }
  }

  return values;
}

/**
 * The keys rendered outside the declared groups, in a stable order.
 *
 * Keys that cannot be written (`business_id`, credential-shaped names) are also
 * filtered: the API would reject them, and `SettingsService::all()` never returns
 * them, so their presence can only mean a hand-edited payload.
 */
export function extraSettingKeys(
  values: SettingsFormValues,
  original: Record<string, string | null>,
): string[] {
  const serverOrder = Object.keys(original);

  return Object.keys(values)
    .filter((key) => !isKnownSettingKey(key) && !isProtectedSettingKey(key))
    .sort((a, b) => {
      // Keep the API's ordering for stored keys, and sort a key added in this
      // session (absent from `original`) alphabetically alongside the rest.
      const indexA = serverOrder.indexOf(a);
      const indexB = serverOrder.indexOf(b);
      if (indexA !== -1 && indexB !== -1) {
        return indexA - indexB;
      }
      if (indexA !== -1) {
        return -1;
      }
      if (indexB !== -1) {
        return 1;
      }
      return a.localeCompare(b);
    });
}

/** Display descriptor for an undeclared key, presented as a plain text field. */
export function fieldForExtraKey(key: string): SettingField {
  return {
    key,
    label: labelForKey(key),
    hint: "Custom setting stored for this business.",
    kind: "text",
  };
}

/**
 * Maps a `422` error bag onto setting keys.
 *
 * `UpdateSettingsRequest` keys its errors by the **setting key** as submitted
 * (`receipt_footer`, `currency`), so unlike every other form in this app the bag
 * must NOT be camelCased: `applyValidationErrors` would rewrite `receipt_footer`
 * to `receiptFooter` and the message would land nowhere. The one non-key entry
 * is the batch-level `settings` error (an empty payload), which is a banner
 * rather than a field and is therefore left to the caller.
 */
export function settingsValidationErrors(errors: ApiValidationErrors): SettingsFieldErrors {
  const mapped: SettingsFieldErrors = {};

  for (const [field, messages] of Object.entries(errors)) {
    const first = messages[0];
    if (first === undefined || first.trim() === "" || field === "settings") {
      continue;
    }
    mapped[field] = first;
  }

  return mapped;
}

/** The batch-level `settings` message of a `422`, when there is one. */
export function settingsBatchError(errors: ApiValidationErrors): string | null {
  return errors.settings?.[0] ?? null;
}
