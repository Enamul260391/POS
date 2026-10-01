import { toText } from "@/lib/api/values";
import type {
  User,
  UserPayload,
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