import { z } from "zod";

/**
 * Form contract of the quick-create category dialog.
 *
 * Client-side validation only, mirroring `StoreCategoryRequest`:
 *   - `name` is required, trimmed and at most 100 characters.
 *
 * Uniqueness is deliberately **not** checked here: `categories` carries a
 * `(business_id, name)` unique index, so a duplicate name can only be decided by
 * Laravel, which answers with a `422` mapped back onto the field by
 * `@/lib/api/validation`. The backend stays the final source of truth.
 */
export const categoryNameSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, "Category name is required")
    .max(100, "Category name is too long"),
});

export type CategoryNameFormValues = z.infer<typeof categoryNameSchema>;

/** Blank state for a fresh dialog. */
export const EMPTY_CATEGORY_NAME_VALUES: CategoryNameFormValues = {
  name: "",
};
