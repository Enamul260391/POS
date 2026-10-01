"use client";

import { memo } from "react";
import { Eye, Pencil, Users } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { UserRoleBadge } from "@/features/users/components/UserRoleBadge";
import { UserStatusBadge } from "@/features/users/components/UserStatusBadge";
import type { User } from "@/types/user";

interface UserTableProps {
  /** Already filtered list — the screen memoises it. */
  users: readonly User[];
  onView: (user: User) => void;
  onEdit: (user: User) => void;
}

/**
 * User directory table with row-level actions (Phase 13).
 *
 * Performance: rows are memoised on the user reference — an add/edit re-renders
 * one row, a filter re-renders only rows that entered or left the visible set.
 * Delete is intentionally absent: removing accounts needs the confirmation
 * flow and API that come with Laravel.
 */
export const UserTable = memo(function UserTable({
  users,
  onView,
  onEdit,
}: UserTableProps) {
  if (users.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <Users className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No users found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search or filter.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[1000px]">
        <TableHeader>
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Phone</TableHead>
            <TableHead>Role</TableHead>
            <TableHead>Branch</TableHead>
            <TableHead>Status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.map((user) => (
            <UserRow key={user.id} user={user} onView={onView} onEdit={onEdit} />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const UserRow = memo(function UserRow({
  user,
  onView,
  onEdit,
}: {
  user: User;
  onView: (user: User) => void;
  onEdit: (user: User) => void;
}) {
  return (
    <TableRow>
      <TableCell className="max-w-44">
        <span className="block truncate font-medium" title={user.name}>
          {user.name}
        </span>
        {user.notes !== null && (
          <span
            className="block truncate text-xs text-muted-foreground"
            title={user.notes}
          >
            {user.notes}
          </span>
        )}
      </TableCell>
      <TableCell className="max-w-48">
        <span className="block truncate" title={user.email}>
          {user.email}
        </span>
      </TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
        {user.phone ?? "—"}
      </TableCell>
      <TableCell>
        <UserRoleBadge roleName={user.roleName} />
      </TableCell>
      <TableCell className="max-w-40">
        <span className="block truncate" title={user.branch ?? undefined}>
          {user.branch ?? "—"}
        </span>
      </TableCell>
      <TableCell>
        <UserStatusBadge status={user.status} />
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`View ${user.name}`}
            onClick={() => onView(user)}
          >
            <Eye aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${user.name}`}
            onClick={() => onEdit(user)}
          >
            <Pencil aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});
