import type { FieldPath, FieldValues, UseFormSetError } from "react-hook-form";

import type { ApiError, ApiValidationErrors } from "@/types/api";

/**
 * Mapping backend validation errors onto form fields.
 *
 * Laravel answers a `422` with an error bag keyed by **column** name
 * (`category_id`, `opening_stock`, `items.0.quantity`). React Hook Form is keyed
 * by the **frontend** field name (`categoryId`, `openingStock`,
 * `items.0.quantity`). Without a translation the messages would land nowhere,
 * so every form that posts to the API declares its aliases once here.
 *
 * The rule is mechanical: `snake_case` ⇄ `camelCase`, plus whatever explicit
 * aliases a form needs (`items.0.product_id` → `items.0.productId`).
 */

/** Converts one `snake_case` API key into its `camelCase` form field name. */
export function toCamelCase(key: string): string {
  return key.replace(/_([a-z0-9])/g, (_, character: string) =>
    character.toUpperCase(),
  );
}

/**
 * Applies the default `snake_case` → `camelCase` translation to an error bag.
 *
 * Array paths (`items.0.quantity`) are handled by translating each segment
 * individually, so the numeric indexes survive.
 */
export function normalizeValidationErrors(
  errors: ApiValidationErrors,
): ApiValidationErrors {
  const normalized: ApiValidationErrors = {};
  for (const [field, messages] of Object.entries(errors)) {
    const key = field
      .split(".")
      .map((segment) => (/^\d+$/.test(segment) ? segment : toCamelCase(segment)))
      .join(".");
    normalized[key] = messages;
  }
  return normalized;
}

/**
 * Field-level messages of a rejected call, already translated.
 *
 * Returns an empty bag for anything that is not a validation failure, so a
 * caller can apply it unconditionally.
 */
export function validationErrorsOf(error: unknown): ApiValidationErrors {
  if (typeof error !== "object" || error === null || !("validationErrors" in error)) {
    return {};
  }
  return normalizeValidationErrors((error as ApiError).validationErrors);
}

/**
 * `true` when the rejection carried at least one field-level message.
 *
 * Used to avoid showing both a per-field error and a generic banner for the
 * same `422`.
 */
export function hasValidationErrors(error: unknown): boolean {
  return Object.keys(validationErrorsOf(error)).length > 0;
}

/** Display-safe message of a rejected call, falling back to a generic wording. */
export function messageOfApiError(
  error: unknown,
  fallback = "Something went wrong. Please try again.",
): string {
  if (
    typeof error === "object" &&
    error !== null &&
    "message" in error &&
    typeof (error as { message: unknown }).message === "string"
  ) {
    const message = (error as { message: string }).message;
    if (message.trim() !== "") {
      return message;
    }
  }
  return fallback;
}

/**
 * Applies a rejected call's messages onto a React Hook Form instance.
 *
 * `setError` is typed with React Hook Form's own `UseFormSetError<TFieldValues>`
 * so the call site keeps full type safety, and the field name is narrowed with a
 * single cast inside this helper: the bag is keyed by `string` (it comes from
 * JSON), while `setError` only accepts the form's own field paths. Keeping that
 * one cast here means no form has to loosen its own `setError`.
 *
 * Unmapped fields (for example a `permission_ids` error on a form that has no
 * such input) are reported as a root error instead, so the user still sees them
 * rather than losing them silently.
 */
export function applyValidationErrors<TFieldValues extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<TFieldValues>,
  knownFields: readonly string[],
): void {
  const messages = validationErrorsOf(error);
  const known = new Set(knownFields);
  const unmapped: string[] = [];

  for (const [field, fieldMessages] of Object.entries(messages)) {
    const first = fieldMessages[0];
    if (first === undefined) {
      continue;
    }
    if (known.has(field)) {
      setError(field as FieldPath<TFieldValues>, { message: first });
    } else {
      unmapped.push(first);
    }
  }

  if (unmapped.length > 0) {
    setError("root", { message: unmapped.join(" ") });
  }
}