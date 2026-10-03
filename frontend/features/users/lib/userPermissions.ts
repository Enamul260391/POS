import type { Permission } from "@/types/permission";
import type { UserPermissionOverrideInput } from "@/types/user";

/**
 * Permission-customisation state for one user account.
 *
 * Nothing about the catalogue is restated here. The rows the editor renders come
 * from `GET /permissions` through `useRoleStore`, their module grouping from
 * `@/features/users/lib/userPermissionModules`, and the selected role's baseline
 * from `GET /roles/{id}` — the same two catalogue sources the role form reads, so
 * this feature cannot present a permission the API does not issue.
 *
 * What lives here is only the state the Add user dialog owns on top of that: the
 * mode that was picked, and which catalogue permissions are ticked.
 */

/**
 * How a user's permissions are decided.
 *
 * - `role`      the account stores no overrides at all, so its effective
 *               permissions come entirely from the selected role — byte for byte
 *               the behaviour an account had before this section existed.
 * - `customize` the account carries user-specific overrides on top of (or
 *               instead of) what the role grants.
 *
 * This is a presentation choice, not a fourth backend concept: the API models
 * exactly one thing (the override set), and "no overrides" is what `role` mode
 * submits.
 */
export type PermissionMode = "role" | "customize";

/** Options of the permission-mode radio group, default first. */
export const PERMISSION_MODE_OPTIONS: readonly {
  value: PermissionMode;
  label: string;
  description: string;
}[] = [
  {
    value: "role",
    label: "By Default",
    description: "User gets the permissions assigned to this role.",
  },
  {
    value: "customize",
    label: "Customize permissions",
    description: "Customize permissions for this user only.",
  },
];

/**
 * The ticked permissions, keyed by global catalogue permission id.
 *
 * This is the *effective* state the administrator sees — "this user may do
 * these" — not the set of overrides, which is derived from it against the role
 * baseline by {@link toOverrideInputs}. Keyed by the numeric id (what a save
 * submits) rather than by slug, because the id is the only identifier the API
 * validates, and keying by it makes a duplicate structurally impossible.
 */
export type PermissionSelection = ReadonlySet<number>;

/** Nothing ticked — the state before a role has been read. */
export const EMPTY_PERMISSION_SELECTION: PermissionSelection = new Set<number>();

/**
 * Ticked permissions → the `overrides` list the sync endpoint takes.
 *
 * The checkbox state is the *effective* state, so the override set is the
 * difference between it and what the role already grants. That difference is
 * exactly one of three cases per permission, and only two of them are rows:
 *
 * | ticked | role grants | override |
 * | ------ | ----------- | -------- |
 * | yes    | yes         | none — unchanged, so it stays inherited |
 * | yes    | no          | `allow` — an extra grant for this user |
 * | no     | yes         | `deny`  — this user loses what the role grants |
 * | no     | no          | none — already unreachable either way |
 *
 * A permission nobody can express a difference about therefore never reaches the
 * backend, which is what keeps "By Default" behaviour intact for every
 * untouched row and honours the API's `DENY > ALLOW > ROLE` resolution model
 * without this module knowing it exists.
 *
 * Iterating the catalogue (rather than the selection) is what guarantees that
 * only ids the API issued are submitted, that each is emitted once (the API
 * validates `distinct`), and that the list is stable.
 *
 * `grantedByRole` must be the selected role's grants — `null` while that role is
 * unknown — in which case nothing can be told apart from anything else and the
 * result is empty, so a save cannot invent overrides from an unread baseline.
 */
export function toOverrideInputs(
  selected: PermissionSelection,
  grantedByRole: ReadonlySet<number> | null,
  permissions: readonly Permission[],
): UserPermissionOverrideInput[] {
  if (grantedByRole === null) {
    return [];
  }

  const inputs: UserPermissionOverrideInput[] = [];

  for (const permission of permissions) {
    const isTicked = selected.has(permission.id);
    const isGrantedByRole = grantedByRole.has(permission.id);

    if (isTicked === isGrantedByRole) {
      continue;
    }

    inputs.push({
      permissionId: permission.id,
      effect: isTicked ? "allow" : "deny",
    });
  }

  return inputs;
}