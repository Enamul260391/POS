"use client";

import { Loader2, Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import { USER_STATUS_FILTER_OPTIONS } from "@/features/users/lib/users";
import {
  selectDirectoryRoles,
  useUserDirectoryStore,
} from "@/stores/useUserDirectoryStore";
import type { UserStatus } from "@/types/user";

interface UserSearchProps {
  query: string;
  onQueryChange: (query: string) => void;
  status: "all" | UserStatus;
  onStatusChange: (status: "all" | UserStatus) => void;
  roleId: number | null;
  onRoleIdChange: (roleId: number | null) => void;
  shownCount: number;
  totalCount: number;
  isLoading: boolean;
}

/**
 * Search box plus status and role filters above the user table.
 *
 * Covers both the "Search" and "Filter" requirements in one row. State stays in
 * `UsersScreen` (local UI state — no store involvement) so the table can memoise
 * on a single derived array. The selects render their own label text, keeping
 * the selected wording in one place.
 *
 * The role options come from the API-backed directory store rather than the
 * mock role catalogue, so a filter or an assignment can never reference a role
 * the backend would reject.
 */
export function UserSearch({
  query,
  onQueryChange,
  status,
  onStatusChange,
  roleId,
  onRoleIdChange,
  shownCount,
  totalCount,
  isLoading,
}: UserSearchProps) {
  const roles = useUserDirectoryStore(selectDirectoryRoles);
  const statusLabel =
    USER_STATUS_FILTER_OPTIONS.find((option) => option.value === status)?.label ??
    "All statuses";
  const roleLabel =
    roleId === null
      ? "All roles"
      : roles.find((role) => role.id === roleId)?.name ?? "All roles";

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <div className="relative min-w-0 flex-1">
        <Search
          className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          type="search"
          className="pl-8"
          placeholder="Search name, email, phone or branch…"
          aria-label="Search users"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              onQueryChange("");
            }
          }}
        />
      </div>

      <Select
        value={status}
        onValueChange={(value) =>
          onStatusChange((value ?? "all") as "all" | UserStatus)
        }
      >
        <SelectTrigger
          aria-label="Filter by status"
          className="w-full sm:w-36"
        >
          <span className={status === "all" ? "text-muted-foreground" : undefined}>
            {statusLabel}
          </span>
        </SelectTrigger>
        <SelectContent>
          {USER_STATUS_FILTER_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {/* Role filter.

          Rendered only once the catalogue is actually available. The API refuses
          it to a user without `roles.view`, and an always-present select with an
          empty dropdown offers a filter that cannot work — worse, it looks like
          a broken control rather than a capability the user does not hold. */}
      {roles.length > 0 && (
        <Select
          value={roleId === null ? null : String(roleId)}
          onValueChange={(value) => {
            if (typeof value === "string" && value !== "") {
              onRoleIdChange(Number(value));
            } else {
              onRoleIdChange(null);
            }
          }}
        >
          <SelectTrigger
            aria-label="Filter by role"
            className="w-full sm:w-40"
          >
            <span className={roleId === null ? "text-muted-foreground" : undefined}>
              {roleLabel}
            </span>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="" disabled>
              All roles
            </SelectItem>
            {roles.map((role) => (
              <SelectItem key={role.id} value={String(role.id)}>
                {role.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}

      <p className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground sm:ml-auto">
        {isLoading && (
          <Loader2 className="size-3 animate-spin" aria-hidden="true" />
        )}
        {shownCount} of {totalCount} users
      </p>
    </div>
  );
}
