import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Category, CategoryPayload } from "@/types/category";

import { toCategories, toCategory } from "../lib/categoryMapper";

/**
 * Category service — the only module that talks to `/categories`.
 *
 * Mapping is applied here rather than in components, so a screen never sees a
 * raw `snake_case` payload and the field-name differences between the API and
 * the domain types stay in one file.
 *
 * Tenancy: no request ever carries a `business_id`. Laravel derives the owning
 * business from the bearer token inside every service, so a client cannot
 * address another tenant's categories.
 */

/** Query parameters accepted by `GET /categories` (`IndexCategoryRequest`). */
export interface CategoryFilters {
  /** Free text over name and slug. */
  search?: string;
  status?: "active" | "inactive";
  page?: number;
  perPage?: number;
  sort?: "name" | "slug" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /categories` (`StoreCategoryRequest`). */
export interface CategoryInput {
  name: string;
  description?: string | null;
  status?: "active" | "inactive";
}

/** Body of `PUT|PATCH /categories/{id}` (`UpdateCategoryRequest`). */
export type CategoryUpdateInput = Partial<CategoryInput>;

/** Maps the frontend filter object onto the query string. */
function toQuery(filters: CategoryFilters | undefined): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    status: filters.status,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/**
 * `GET /categories` — one page of categories, filtered and sorted server-side.
 *
 * Filtering happens in SQL; nothing is fetched-and-filtered in the browser.
 */
export async function listCategories(
  filters?: CategoryFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Category>> {
  const data = await apiList<CategoryPayload>("/categories", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toCategories(data.items), pagination: data.pagination };
}

/**
 * `GET /categories` without pagination, for the small closed sets the POS and
 * the product form need (category tabs, select options).
 *
 * Uses a large `per_page` rather than fetching everything: the backend caps a
 * page at 100, so a catalogue with more categories than that still needs the
 * paged path — the screens that use this only ever show a select or a tab bar.
 */
export async function listAllCategories(
  config?: ApiRequestConfig,
): Promise<Category[]> {
  const data = await apiList<CategoryPayload>("/categories", {
    params: { per_page: 100, sort: "name", direction: "asc" },
    signal: config?.signal,
  });

  return toCategories(data.items);
}

/** `GET /categories/{id}` — one category. */
export async function getCategory(
  id: number,
  config?: ApiRequestConfig,
): Promise<Category> {
  const payload = await apiGet<CategoryPayload>(`/categories/${id}`, {
    signal: config?.signal,
  });
  return toCategory(payload);
}

/** `POST /categories` — creates a category; the API derives the slug. */
export async function createCategory(input: CategoryInput): Promise<Category> {
  const payload = await apiPost<CategoryPayload>("/categories", {
    name: input.name,
    description: input.description ?? null,
    status: input.status ?? "active",
  });
  return toCategory(payload);
}

/** `PUT|PATCH /categories/{id}` — partial update of the supplied fields. */
export async function updateCategory(
  id: number,
  input: CategoryUpdateInput,
): Promise<Category> {
  const payload = await apiPatch<CategoryPayload>(`/categories/${id}`, {
    ...(input.name === undefined ? {} : { name: input.name }),
    ...(input.description === undefined ? {} : { description: input.description }),
    ...(input.status === undefined ? {} : { status: input.status }),
  });
  return toCategory(payload);
}

/**
 * `DELETE /categories/{id}` — soft-deletes the category.
 *
 * A category still referenced by products is refused by the service layer with
 * a `409`; that message is surfaced verbatim by the shared error handling.
 */
export async function deleteCategory(id: number): Promise<void> {
  await apiDelete<null>(`/categories/${id}`);
}