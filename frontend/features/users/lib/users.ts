import type { UserStatus } from "@/types/user";

/**
 * Query helpers for the user screens (Phase 13).
 *
 * Owns the status option list the filter and the form both render.
 *
 * There is deliberately no client-side `filterUsers` here any more: the screen
 * filters and paginates server-side through `GET /users`, so a local filter
 * would only ever re-filter one page of an already-filtered result set. The
 * directory total likewise comes from the API's pagination block, not from the
 * length of the loaded array.
 */

/** Status filter options for the user table, preceded by "All". */
export const USER_STATUS_FILTER_OPTIONS: readonly {
  value: "all" | UserStatus;
  label: string;
}[] = [
  { value: "all", label: "All statuses" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];
