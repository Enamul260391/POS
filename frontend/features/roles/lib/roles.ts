import type { PermissionKey } from "@/types/permission";
import type { Role } from "@/types/role";

/**
 * Query helpers for the role screens.
 *
 * Pure functions so the roles screen and the role form stay presentational.
 */

/** Display label for a role, used by the delete confirmation. */
export function roleLabel(role: Role): string {
  return role.name;
}

/** `true` when `role` grants the permission identified by `key`. */
export function hasPermission(role: Role, key: PermissionKey): boolean {
  return role.permissions.some((permission) => permission.key === key);
}

/**
 * `true` when the API will accept a delete for this role.
 *
 * `RoleService::delete()` refuses with a `409` while any user holds the role,
 * so the button is disabled from the same fact the API uses — `usersCount` —
 * rather than from a client-side "is this a system role" guess the API does not
 * model. The server check remains authoritative; this only avoids offering an
 * action that is certain to fail.
 */
export function isDeletableRole(role: Role): boolean {
  return role.usersCount === 0;
}
