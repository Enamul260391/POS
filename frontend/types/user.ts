/**
 * Lifecycle state of a user account.
 * - `active`   can sign in (UI-only; there is no real authentication yet)
 * - `inactive` suspended; hidden from the active count
 */
export type UserStatus = "active" | "inactive";

/**
 * A staff login account.
 *
 * The shop is single-branch for now, so `branch` is a placeholder free-text
 * field; multi-branch selection arrives with Laravel. `roleName` is a snapshot
 * of the role name at assignment so the table can render it without a join,
 * exactly like `Supplier.supplierName` does for purchases. Passwords are never
 * stored on the entity — the form collects them only to keep the add-user flow
 * realistic until real authentication lands.
 */
export interface User {
  /** Primary key. */
  id: number;
  /** Full name, e.g. "Samira Akter". */
  name: string;
  /** Unique login email. */
  email: string;
  /** Primary contact number. `null` when unknown. */
  phone: string | null;
  /** Foreign key to `Role`. */
  roleId: number;
  /** Role name snapshot for display. */
  roleName: string;
  /** Branch placeholder, e.g. "Main – Dhaka". `null` when unset. */
  branch: string | null;
  /** Account lifecycle state. */
  status: UserStatus;
  /** Free text note, e.g. "On call weekends". `null` when empty. */
  notes: string | null;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Minimal role shape the users screen needs.
 *
 * Deliberately narrower than `@/types/role`: the staff directory only picks a
 * role and shows its name, so it does not pull the permission matrix or the
 * user count into this feature. Keeping the two apart is what lets the
 * `/users` screen read the real catalogue from the API without dragging the
 * `/roles` screen's state and pagination along with it.
 */
export interface UserRoleOption {
  /** Primary key — submitted as `role_id`. */
  id: number;
  /** Display name, e.g. "Manager". */
  name: string;
  /** Machine handle, e.g. "salesman". Shown under the name in the picker. */
  slug: string;
}

/** Raw `UserDirectoryResource` payload. */
export interface UserPayload {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  branch: string | null;
  status: string;
  notes: string | null;
  /** The role the screen edits; `null` when the account holds none yet. */
  role: UserRoleOption | null;
  /** Every role held, since the relation is many-to-many. */
  roles: UserRoleOption[];
  created_at: string;
  updated_at: string;
}

/** Raw `RoleResource` payload, trimmed to what the picker reads. */
export interface UserRolePayload {
  id: number;
  name: string;
  slug: string;
}
