import { toText } from "@/lib/api/values";
import type { Category, CategoryPayload, CategoryStatus } from "@/types/category";

/**
 * `CategoryResource` → `Category`.
 *
 * The only real work is renaming `products_count` and narrowing `status` to the
 * union the UI switches on. Unknown status values degrade to `inactive` rather
 * than rendering a blank badge, which is also the safe default: an
 * unrecognised category must not appear in the POS.
 */
export function toCategory(payload: CategoryPayload): Category {
  return {
    id: payload.id,
    name: payload.name,
    slug: payload.slug,
    description: toText(payload.description),
    productCount: payload.products_count ?? 0,
    status: payload.status === "active" ? "active" : "inactive",
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of categories. */
export function toCategories(payloads: readonly CategoryPayload[]): Category[] {
  return payloads.map(toCategory);
}

/** Re-exported so screens can narrow a status without importing the type file. */
export type { CategoryStatus };