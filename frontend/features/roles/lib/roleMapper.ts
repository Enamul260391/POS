import { toText } from "@/lib/api/values";
import { toPermissions } from "@/features/roles/lib/permissionMapper";
import type { Role, RolePayload } from "@/types/role";

/**
 * `RoleResource` → `Role`.
 *
 * The two conditional keys are read defensively for the same reason the
 * permission mapper does: `permissions` is emitted via `whenLoaded` and
 * `users_count` via `whenCounted`, so a mapper that assumed presence would
 * produce `undefined` rather than an empty collection. The "Users" column and
 * the permission matrix both depend on those being real values, so the defaults
 * are `[]` and `0` rather than `undefined`.
 *
 * `business_id` is intentionally not read: ownership is backend state, and the
 * role list the API returns is already scoped to the caller's business.
 */
export function toRole(payload: RolePayload): Role {
  return {
    id: payload.id,
    name: payload.name,
    slug: payload.slug,
    description: toText(payload.description),
    permissions: payload.permissions === undefined ? [] : toPermissions(payload.permissions),
    usersCount: payload.users_count ?? 0,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of roles. */
export function toRoles(payloads: readonly RolePayload[]): Role[] {
  return payloads.map(toRole);
}