import type { Permission, PermissionPayload } from "@/types/permission";

/**
 * Role — a named bundle of permissions assigned to users.
 *
 * Mirrors `RoleResource`. Ownership is deliberately absent: `business_id` never
 * leaves the backend, which resolves it from the bearer token, so a role from
 * another tenant is indistinguishable from one that does not exist.
 *
 * `slug` is present but read-only — `RoleService` derives it from `name` on
 * every save to keep the unique `(business_id, slug)` index satisfiable, so it
 * is not a value the client may choose.
 *
 * The API does not model a "system role" flag: a role is deletable whenever it
 * is unassigned, which is what `usersCount` reports.
 */
export interface Role {
  /** Primary key. */
  id: number;
  /** Display name, e.g. "Administrator". */
  name: string;
  /** Canonical handle, e.g. "admin". Derived from `name` by the backend. */
  slug: string;
  /** Free text description. `null` when none was given. */
  description: string | null;
  /** The permissions this role grants, as returned by the API. */
  permissions: Permission[];
  /** How many users currently hold the role. */
  usersCount: number;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Raw `RoleResource` payload. */
export interface RolePayload {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  /** Emitted only when the relation was eager-loaded (every endpoint does). */
  permissions?: PermissionPayload[];
  /** Emitted only when the query counted it (every endpoint does). */
  users_count?: number;
  created_at: string;
  updated_at: string;
}

/** Body of `POST /roles` (`StoreRoleRequest`). */
export interface RoleInput {
  name: string;
  description: string | null;
  permissionIds: number[];
}

/** Body of `PUT|PATCH /roles/{id}` (`UpdateRoleRequest`). */
export type RoleUpdateInput = RoleInput;
