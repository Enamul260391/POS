import type { Permission, PermissionKey } from "@/types/permission";

/**
 * Grouping of the permission catalogue for the role form's matrix.
 *
 * The API models a permission as `{ id, name, slug, description }` and nothing
 * else — there is no `group` column — so the grouping the matrix renders under
 * is derived from the slug's own prefix (`products.create` → "Products"). That
 * keeps the section headers in step with the catalogue automatically: a
 * permission added by a reseed appears under its own area without a frontend
 * edit, and the hardcoded list this replaced could never drift out of date.
 *
 * Display labels for the prefixes the application defines; anything else falls
 * back to a title-cased prefix so an unknown area still renders sensibly.
 */
const GROUP_LABELS: Record<string, string> = {
  dashboard: "Dashboard",
  pos: "POS",
  products: "Products",
  inventory: "Inventory",
  sales: "Sales",
  purchases: "Purchases",
  customers: "Customers",
  suppliers: "Suppliers",
  expenses: "Expenses",
  reports: "Reports",
  users: "Users",
  roles: "Roles",
  permissions: "Permissions",
  settings: "Settings",
};

/** The area a permission belongs to: everything before the first `.`. */
export function permissionPrefix(key: PermissionKey): string {
  const index = key.indexOf(".");
  return index === -1 ? key : key.slice(0, index);
}

/** Human label for a permission's area, e.g. "products" → "Products". */
export function permissionGroupLabel(key: PermissionKey): string {
  const prefix = permissionPrefix(key);
  const known = GROUP_LABELS[prefix];
  if (known !== undefined) {
    return known;
  }
  if (prefix === "") {
    return "Other";
  }
  return prefix.charAt(0).toUpperCase() + prefix.slice(1);
}

/** One grouping header and the permissions that live under it. */
export interface PermissionGroup {
  /** Display header, e.g. "Products". */
  group: string;
  /** The slug prefix the header was derived from, used as a stable key. */
  prefix: string;
  /** Permissions under this header, in catalogue order. */
  permissions: readonly Permission[];
}

/**
 * Groups a permission catalogue for the matrix.
 *
 * Group order follows the order the areas first appear in the catalogue, which
 * is seeder order (the service is asked for `sort=id`), so the matrix is stable
 * between loads instead of being reshuffled alphabetically. One pass, so the
 * result can be memoised by the caller.
 */
export function permissionsByGroup(
  permissions: readonly Permission[],
): PermissionGroup[] {
  const groups = new Map<string, { label: string; permissions: Permission[] }>();

  for (const permission of permissions) {
    const prefix = permissionPrefix(permission.key);
    const existing = groups.get(prefix);
    if (existing === undefined) {
      groups.set(prefix, {
        label: permissionGroupLabel(permission.key),
        permissions: [permission],
      });
    } else {
      existing.permissions.push(permission);
    }
  }

  return [...groups.entries()].map(([prefix, entry]) => ({
    group: entry.label,
    prefix,
    permissions: entry.permissions,
  }));
}

/** Looks up a permission label by key, falling back to the raw key. */
export function permissionLabel(
  permissions: readonly Permission[],
  key: PermissionKey,
): string {
  return permissions.find((permission) => permission.key === key)?.label ?? key;
}