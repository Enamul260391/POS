/**
 * Permission model for Laravel-style authorization.
 *
 * Permissions are a **global** catalogue in the API (`PermissionResource`): a
 * row carries no `business_id`, so a permission is a system-wide definition and
 * a business grants a subset of it to its own roles through `permission_role`.
 * They are read-only over the API — the seeder is the only writer.
 *
 * The API keys a permission by its `slug` (`products.create`, the exact string
 * the Gate definitions resolve against) and labels it with `name`. The UI keeps
 * that slug as `key`, so the value a row is identified by in the matrix is the
 * same one the backend authorises on, while the numeric `id` is what a save
 * submits back.
 */

/** Stable handle of a permission, e.g. `"products.create"`. */
export type PermissionKey = string;

/** A granular permission over one application area. */
export interface Permission {
  /** Primary key — submitted as `permission_id`. */
  id: number;
  /** Unique `area.action` handle; the API's `slug`. */
  key: PermissionKey;
  /** Human label shown in the matrix, e.g. "Create product". */
  label: string;
  /** Optional longer hint. `null` when the catalogue has none. */
  description: string | null;
  /** How many roles currently grant it. `0` when the API did not count. */
  rolesCount: number;
}

/** Raw `PermissionResource` payload. */
export interface PermissionPayload {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  /** Only present on `/permissions`, which counts the relation. */
  roles_count?: number;
  created_at: string;
  updated_at: string;
}
