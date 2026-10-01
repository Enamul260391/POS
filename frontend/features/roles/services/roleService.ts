import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Permission, PermissionPayload } from "@/types/permission";
import type { Role, RoleInput, RolePayload, RoleUpdateInput } from "@/types/role";

import { toPermissions } from "../lib/permissionMapper";
import { toRole, toRoles } from "../lib/roleMapper";

/**
 * Role service — the only module that talks to `/roles` and `/permissions`.
 *
 * Tenancy: no request carries a `business_id`, and every role is addressed by
 * an id the caller's own business issued. Laravel resolves the owning business
 * from the bearer token and scopes each read and write to it, so ownership is
 * never something the client asserts.
 *
 * `slug` is never sent: `RoleService` derives it from `name` on every save, so
 * submitting one would be ignored and could only disagree with the stored
 * handle.
 */

/** Columns the API accepts for ordering (`RoleService::SORTABLE_COLUMNS`). */
export type RoleSortColumn = "name" | "slug" | "created_at" | "id";

/** Query parameters accepted by `GET /roles` (`IndexRoleRequest`). */
export interface RoleFilters {
  /** Free text over name, slug and description. */
  search?: string;
  page?: number;
  perPage?: number;
  sort?: RoleSortColumn;
  direction?: "asc" | "desc";
}

/** Largest page the API will return (`RoleService::MAX_PER_PAGE`). */
const MAX_PER_PAGE = 100;

/** Maps the frontend filter object onto the query string. */
function toRoleQuery(
  filters: RoleFilters | undefined,
): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/**
 * Shared body builder, so create and update can never drift apart.
 *
 * `permission_ids` is always sent, including as `[]`: `UpdateRoleRequest`
 * treats an omitted field as "keep the existing grants" but an explicit empty
 * array as "remove them all", and the form always submits the full ticked set.
 * Ids are de-duplicated because the API rejects a repeat with a `422`
 * (`distinct`).
 */
function toRoleBody(input: RoleInput | RoleUpdateInput): Record<string, unknown> {
  return {
    name: input.name.trim(),
    description: input.description?.trim() || null,
    permission_ids: [...new Set(input.permissionIds)],
  };
}

/** `GET /roles` — one page, filtered and sorted server-side. */
export async function listRoles(
  filters?: RoleFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Role>> {
  const data = await apiList<RolePayload>("/roles", {
    params: toRoleQuery(filters),
    signal: config?.signal,
  });

  return { items: toRoles(data.items), pagination: data.pagination };
}

/** `GET /roles/{id}` — one role. */
export async function getRole(
  id: number,
  config?: ApiRequestConfig,
): Promise<Role> {
  const payload = await apiGet<RolePayload>(`/roles/${id}`, {
    signal: config?.signal,
  });
  return toRole(payload);
}

/** `POST /roles` — creates a role for the caller's business. */
export async function createRole(input: RoleInput): Promise<Role> {
  return toRole(await apiPost<RolePayload>("/roles", toRoleBody(input)));
}

/** `PUT|PATCH /roles/{id}` — updates identity and replaces the grants. */
export async function updateRole(
  id: number,
  input: RoleUpdateInput,
): Promise<Role> {
  return toRole(
    await apiPatch<RolePayload>(`/roles/${id}`, toRoleBody(input)),
  );
}

/**
 * `DELETE /roles/{id}` — removes a role that no user holds.
 *
 * The API refuses with `409` while the role is still assigned, rather than
 * silently stripping authorisations from live accounts. That rejection is left
 * to propagate so the caller can show the API's own wording.
 */
export async function deleteRole(id: number): Promise<void> {
  await apiDelete<null>(`/roles/${id}`);
}

/**
 * `GET /permissions` — the global catalogue, read once for the whole screen.
 *
 * The catalogue is global and seeded, so it is small and changes only on a
 * reseed; it is fetched with the maximum page size rather than paged by the
 * caller, which is what the role form's "N of M granted" counter needs.
 *
 * Sorted by `id`, which is seeder order, so the matrix keeps the grouping order
 * the catalogue was defined in rather than an alphabetical reshuffle.
 */
export async function listPermissions(
  config?: ApiRequestConfig,
): Promise<Permission[]> {
  const data = await apiList<PermissionPayload>("/permissions", {
    params: { per_page: MAX_PER_PAGE, page: 1, sort: "id", direction: "asc" },
    signal: config?.signal,
  });

  return toPermissions(data.items);
}