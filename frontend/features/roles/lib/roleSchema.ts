import { z } from "zod";

import type { Permission } from "@/types/permission";
import type { Role } from "@/types/role";

/**
 * Form contract of the Create/Edit role dialog.
 *
 * One schema factory serves validation and typing. `createRoleSchema` closes
 * name uniqueness over the catalogue (excluding the row being edited), which
 * mirrors the `Rule::unique('roles', 'name')` Laravel applies inside the
 * caller's own business.
 *
 * There is no `slug` field any more: `StoreRoleRequest` / `UpdateRoleRequest`
 * do not accept one, because `RoleService` derives the handle from the name to
 * keep the unique `(business_id, slug)` index satisfiable. The API stays the
 * authority on uniqueness either way â€” the local check only gives feedback
 * without a round trip, and its message is replaced by the API's on conflict.
 *
 * Permissions are submitted as numeric ids rather than `area.action` keys:
 * `permission_ids` is what the pivot table stores, and a key would have to be
 * re-resolved to an id the catalogue may have renumbered.
 */

const roleFields = {
  name: z
    .string()
    .trim()
    .min(1, "Name is required")
    // `Role::MAX_NAME_LENGTH`.
    .max(50, "Name is too long"),
  description: z
    .string()
    .trim()
    // `StoreRoleRequest` caps the column at 1000 characters.
    .max(1000, "Description is too long"),
  permissionIds: z.array(
    z
      .number({ invalid_type_error: "Invalid permission" })
      .int("Invalid permission"),
  ),
};

/** Valid shape of the role form. */
export const baseRoleSchema = z.object(roleFields);

export type RoleFormValues = z.infer<typeof baseRoleSchema>;

/** Fields a `422` from the API can be mapped onto. */
export const ROLE_FORM_FIELDS = [
  "name",
  "description",
  "permissionIds",
] as const;

/**
 * Schema for one save: base fields plus the rules that need the catalogue â€”
 * name uniqueness (excluding the role being edited) and a `permissionIds` set
 * that only references permissions the API actually issued.
 */
export function createRoleSchema(
  existing: readonly Role[],
  permissions: readonly Permission[],
  editingId: number | null,
): z.ZodEffects<z.ZodObject<typeof roleFields>> {
  return baseRoleSchema.superRefine((values, ctx) => {
    if (
      existing.some(
        (role) =>
          role.id !== editingId &&
          role.name.toLowerCase() === values.name.trim().toLowerCase(),
      )
    ) {
      ctx.addIssue({
        code: "custom",
        message: "Role name already exists",
        path: ["name"],
      });
    }

    const known = new Set(permissions.map((permission) => permission.id));
    if (values.permissionIds.some((id) => !known.has(id))) {
      ctx.addIssue({
        code: "custom",
        message: "Unknown permission",
        path: ["permissionIds"],
      });
    }
  });
}

/** Blank state for "Create role". */
export const EMPTY_ROLE_VALUES: RoleFormValues = {
  name: "",
  description: "",
  permissionIds: [],
};

/** Maps an existing role into form state (edit mode). */
export function toRoleFormValues(role: Role): RoleFormValues {
  return {
    name: role.name,
    description: role.description ?? "",
    permissionIds: role.permissions.map((permission) => permission.id),
  };
}
