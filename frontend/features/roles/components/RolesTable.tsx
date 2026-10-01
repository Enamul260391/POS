"use client";

import { memo } from "react";
import { Pencil, Shield, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { isDeletableRole } from "@/features/roles/lib/roles";
import type { Role } from "@/types/role";

interface RolesTableProps {
  /** Already filtered list — the screen memoises it. */
  roles: readonly Role[];
  /** Size of the global permission catalogue, for the "N of M" column. */
  totalPermissions: number;
  onEdit: (role: Role) => void;
  onDelete: (role: Role) => void;
}

/**
 * Role list table.
 *
 * Rows are memoised on the role reference — an add/edit re-renders one row, a
 * filter re-renders only rows that entered or left the visible set.
 *
 * The "Users" column is the API's `users_count` rather than a count derived
 * from a user store, so it stays correct for accounts this screen never loads.
 * Delete is offered only when no user holds the role, which is the same rule
 * `RoleService::delete()` enforces with a `409`.
 */
export const RolesTable = memo(function RolesTable({
  roles,
  totalPermissions,
  onEdit,
  onDelete,
}: RolesTableProps) {
  if (roles.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <Shield className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No roles found</p>
        <p className="text-xs text-muted-foreground">Try a different search.</p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[900px]">
        <TableHeader>
          <TableRow>
            <TableHead>Role</TableHead>
            <TableHead>Slug</TableHead>
            <TableHead className="text-right">Permissions</TableHead>
            <TableHead className="text-right">Users</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {roles.map((role) => (
            <RoleRow
              key={role.id}
              role={role}
              totalPermissions={totalPermissions}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const RoleRow = memo(function RoleRow({
  role,
  totalPermissions,
  onEdit,
  onDelete,
}: {
  role: Role;
  totalPermissions: number;
  onEdit: (role: Role) => void;
  onDelete: (role: Role) => void;
}) {
  const canDelete = isDeletableRole(role);

  return (
    <TableRow>
      <TableCell className="max-w-44">
        <span className="block truncate font-medium" title={role.name}>
          {role.name}
        </span>
        {role.description !== null && (
          <span
            className="block truncate text-xs text-muted-foreground"
            title={role.description}
          >
            {role.description}
          </span>
        )}
      </TableCell>
      <TableCell className="whitespace-nowrap font-mono text-xs text-muted-foreground">
        {role.slug}
      </TableCell>
      <TableCell className="text-right tabular-nums">
        {role.permissions.length}/{totalPermissions}
      </TableCell>
      <TableCell className="text-right tabular-nums">{role.usersCount}</TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${role.name}`}
            onClick={() => onEdit(role)}
          >
            <Pencil aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Delete ${role.name}`}
            // The API refuses a delete while the role is assigned, so the action
            // is hidden rather than offered and then rejected.
            disabled={!canDelete}
            title={
              canDelete
                ? undefined
                : "This role is assigned to one or more users and cannot be deleted."
            }
            onClick={() => onDelete(role)}
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});
