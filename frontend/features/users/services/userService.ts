import { apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type {
  User,
  UserPayload,
  UserPermissionOverride,
  UserPermissionOverrideInput,
  UserPermissionOverridesPayload,
  UserRoleOption,
  UserRolePayload,
  UserStatus,
} from "@/types/user";

import {
  toUser,
  toUserPermissionOverrides,
  toUserRole,
  toUsers,
} from "../lib/userMapper";

/**
 * User service — the only module that talks to `/users`.
 *
 * Tenancy: no request carries a `business_id`. Laravel resolves the owning
 * business from the bearer token, so another tenant's staff are unreachable.
 *
 * The role catalogue is read from the existing `/roles` endpoint rather than a
 * `/users/roles` alias, so the picker shows exactly the roles the roles
 * management screen manages.
 */

/** Query parameters accepted by `GET /users` (`IndexUserRequest`). */
export interface UserFilters {
  /** Free text over name, email, phone and branch. */
  search?: string;
  status?: UserStatus;
  /** Restrict to one role; `undefined` disables the filter. */
  roleId?: number;
  page?: number;
  perPage?: number;
  sort?: "name" | "email" | "status" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /users` (`StoreUserRequest`). */
export interface UserInput {
  name: string;
  email: string;
  phone?: string | null;
  branch?: string | null;
  status?: UserStatus;
  notes?: string | null;
  roleId: number;
  password?: string;
}

/** Body of `PUT|PATCH /users/{id}` (`UpdateUserRequest`). */
export type UserUpdateInput = Partial<UserInput>;

/** Maps the frontend filter object onto the query string. */
function toQuery(
  filters: UserFilters | undefined,
): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    status: filters.status,
    role_id: filters.roleId,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/**
 * Shared body builder, so create and update can never drift apart.
 *
 * The role is sent as `role_id` (Laravel's column spelling); the validation
 * error comes back under the same key and is translated to `roleId` by
 * `@/lib/api/validation`.
 */
function toUserBody(
  input: UserInput | UserUpdateInput,
): Record<string, unknown> {
  return {
    name: input.name?.trim() ?? "",
    email: input.email?.trim().toLowerCase() ?? "",
    phone: input.phone?.trim() || null,
    branch: input.branch?.trim() || null,
    status: input.status ?? "active",
    notes: input.notes?.trim() || null,
    role_id: input.roleId,
    // An empty password on an edit means "keep the current one" — the service
    // ignores it, so it is sent as an empty string rather than omitted.
    password: input.password ?? "",
  };
}

/** `GET /users` — one page, filtered and sorted server-side. */
export async function listUsers(
  filters?: UserFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<User>> {
  const data = await apiList<UserPayload>("/users", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toUsers(data.items), pagination: data.pagination };
}

/** `GET /roles` — the catalogue the role picker and filter render. */
export async function listUserRoles(
  config?: ApiRequestConfig,
): Promise<UserRoleOption[]> {
  const data = await apiList<UserRolePayload>("/roles", {
    params: { per_page: 100, page: 1 },
    signal: config?.signal,
  });

  return data.items.map(toUserRole);
}

/** `GET /users/{id}` — one user. */
export async function getUser(
  id: number,
  config?: ApiRequestConfig,
): Promise<User> {
  const payload = await apiGet<UserPayload>(`/users/${id}`, {
    signal: config?.signal,
  });
  return toUser(payload);
}

/** `POST /users` — creates a staff account with one role. */
export async function createUser(input: UserInput): Promise<User> {
  const payload = await apiPost<UserPayload>("/users", toUserBody(input));
  return toUser(payload);
}

/** `PUT|PATCH /users/{id}` — partial update of the supplied fields. */
export async function updateUser(
  id: number,
  input: UserUpdateInput,
): Promise<User> {
  const payload = await apiPatch<UserPayload>(
    `/users/${id}`,
    toUserBody(input),
  );
  return toUser(payload);
}

/**
 * `GET /users/{id}/permission-overrides` — the target user's stored overrides.
 *
 * Only rows with an effect the model declares are returned, so a value this
 * screen did not recognise never reaches the editor.
 */
export async function listUserPermissionOverrides(
  userId: number,
  config?: ApiRequestConfig,
): Promise<UserPermissionOverride[]> {
  const payload = await apiGet<UserPermissionOverridesPayload>(
    `/users/${userId}/permission-overrides`,
    { signal: config?.signal },
  );
  return toUserPermissionOverrides(payload);
}

/**
 * `PUT|PATCH /users/{id}/permission-overrides` — replaces the whole set.
 *
 * The list is the *complete* desired state, so an empty array removes every
 * override and any permission left out of it is cleared. Entries are
 * de-duplicated by `permission_id` before sending: the API rejects a repeat with
 * a `422` (`distinct`), and the `user_permissions` unique index would refuse the
 * second row anyway.
 */
export async function syncUserPermissionOverrides(
  userId: number,
  overrides: readonly UserPermissionOverrideInput[],
): Promise<UserPermissionOverride[]> {
  const seen = new Set<number>();
  const items = overrides.filter((override) => {
    if (seen.has(override.permissionId)) {
      return false;
    }
    seen.add(override.permissionId);
    return true;
  });

  const payload = await apiPatch<UserPermissionOverridesPayload>(
    `/users/${userId}/permission-overrides`,
    {
      overrides: items.map((override) => ({
        permission_id: override.permissionId,
        effect: override.effect,
      })),
    },
  );
  return toUserPermissionOverrides(payload);
}