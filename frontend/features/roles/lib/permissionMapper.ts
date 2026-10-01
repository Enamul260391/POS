import { toText } from "@/lib/api/values";
import type { Permission, PermissionPayload } from "@/types/permission";

/**
 * `PermissionResource` → `Permission`.
 *
 * The API names the human label `name` and the authorising handle `slug`; the
 * UI calls them `label` and `key` so a row reads the same everywhere it is
 * rendered. `roles_count` is only present when the query counted it, so it is
 * read defensively — a permission nested inside a role carries no count and
 * must not be reported as granting nothing.
 */
export function toPermission(payload: PermissionPayload): Permission {
  return {
    id: payload.id,
    key: payload.slug,
    label: payload.name,
    description: toText(payload.description),
    rolesCount: payload.roles_count ?? 0,
  };
}

/** Convenience wrapper for a whole page of permissions. */
export function toPermissions(
  payloads: readonly PermissionPayload[],
): Permission[] {
  return payloads.map(toPermission);
}