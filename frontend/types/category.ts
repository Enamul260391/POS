/**
 * Raw `CategoryResource` payload.
 *
 * `products_count` is only present when the query counted it (every category
 * endpoint does), and there is no `parent_id`, `image` or `business_id` in the
 * API — the owning business is implied by the token and nesting is not part of
 * the Phase 20 schema.
 */
export interface CategoryPayload {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  status: string;
  products_count?: number;
  created_at: string;
  updated_at: string;
}

/**
 * Product category used to group the catalogue.
 *
 * Phase 25: the domain type now mirrors what the API actually returns. The
 * Phase 20 `categories` table has **no `parent_id` and no `image` column**, so
 * the previously modelled `parentId` / `image` fields were removed rather than
 * being fed with invented values. Categories are therefore a flat list: the POS
 * tabs render every active category and a product matches exactly one of them.
 */

/** Whether the category can be used inside the POS terminal. */
export type CategoryStatus = "active" | "inactive";

export interface Category {
  /** Primary key. */
  id: number;
  /** Display name, e.g. "Cold Drinks". */
  name: string;
  /** Unique, URL friendly identifier, derived by the API from the name. */
  slug: string;
  /** Optional short description. */
  description: string | null;
  /**
   * Number of products assigned to this category, aggregated by the API
   * (`withCount('products')`).
   */
  productCount: number;
  /** Inactive categories are hidden from the POS terminal. */
  status: CategoryStatus;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}
