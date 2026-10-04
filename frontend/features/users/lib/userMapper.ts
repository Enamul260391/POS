import { toText } from "@/lib/api/values";
import type {
  User,
  UserPayload,
  UserPermissionEffect,
  UserPermissionOverride,
  UserPermissionOverridePayload,
  UserPermissionOverridesPayload,
  UserRoleOption,
  UserRolePayload,
  UserStatus,
} from "@/types/user";

/**
 * `UserDirectoryResource` → `User`.
 *
 * The screen still models one role per row (`roleId` / `roleName`), so the
 * payload's `role` object is flattened into that pair. A user with no role at
 * all is a legitimate state the API can return, so `roleId` falls back to `0` —
 * the same "nothing selected" sentinel `EMPTY_USER_VALUES` uses, rather than a
 * fabricated role that would fail the form's `min(1)` check.
 *
 * `status` is narrowed to the union the UI switches on, with anything unknown
 * treated as `inactive`: an unrecognised account must never look usable.
 */
export function toUser(payload: UserPayload): User {
  return {
    id: payload.id,
    name: payload.name,
    email: payload.email,
    phone: toText(payload.phone),
    roleId: payload.role?.id ?? 0,
    roleName: payload.role?.name ?? "—",
    branch: toText(payload.branch),
    status: toUserStatus(payload.status),
    notes: toText(payload.notes),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of users. */
export function toUsers(payloads: readonly UserPayload[]): User[] {
  return payloads.map(toUser);
}

/** Narrows the API's `status` string. */
export function toUserStatus(value: string): UserStatus {
  return value === "inactive" ? "inactive" : "active";
}

/** `RoleResource` → the role shape the picker and filter render. */
export function toUserRole(payload: UserRolePayload): UserRoleOption {
  return { id: payload.id, name: payload.name, slug: payload.slug };
}

/**
 * Narrows the API's `effect` string to the two states the model declares.
 *
 * The value arrives as a plain `string`, so it is narrowed rather than trusted:
 * anything that is neither `allow` nor `deny` is dropped by the caller, because
 * an unknown effect must never be submitted back as an override.
 */
export function toUserPermissionEffect(value: string): UserPermissionEffect | null {
  return value === "allow" || value === "deny" ? value : null;
}

/**
 * `UserPermissionOverrideResource` → `UserPermissionOverride`.
 *
 * A row whose effect the model does not declare maps to `null`, so a screen can
 * filter it out instead of rendering (and later resubmitting) a state the API
 * would reject.
 */
export function toUserPermissionOverride(
  payload: UserPermissionOverridePayload,
): UserPermissionOverride | null {
  const effect = toUserPermissionEffect(payload.effect);
  if (effect === null) {
    return null;
  }

  return {
    id: payload.id,
    permissionId: payload.permission_id,
    permissionKey: payload.permission?.slug ?? null,
    permissionLabel: toText(payload.permission?.name),
    effect,
  };
}

/**
 * Unwraps the `items` block of the override endpoints.
 *
 * The controller nests a resource collection in the response envelope, which
 * Laravel serialises as `{ data: [...] }`, while the mapper is easier to reason
 * about with a plain array — so both are accepted here.
 */
export function toUserPermissionOverrides(
  payload: UserPermissionOverridesPayload,
): UserPermissionOverride[] {
  const items = Array.isArray(payload.items)
    ? payload.items
    : (payload.items?.data ?? []);

  return items
    .map(toUserPermissionOverride)
    .filter((override): override is UserPermissionOverride => override !== null);
}