"use client";

import { formatLocalDateTime } from "@/lib/datetime";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { UserRoleBadge } from "@/features/users/components/UserRoleBadge";
import { UserStatusBadge } from "@/features/users/components/UserStatusBadge";
import type { User } from "@/types/user";

interface UserDetailsProps {
  user: User;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Read-only profile view of a user (Phase 13).
 *
 * Passwords never travel on the entity, so there is nothing to show for it —
 * resetting one goes through the edit dialog instead. Mounted only while open.
 */
export function UserDetails({ user, onOpenChange }: UserDetailsProps) {
  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{user.name}</DialogTitle>
          <DialogDescription>
            {user.roleName} · {user.email}
          </DialogDescription>
        </DialogHeader>

        <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
          <dt className="text-muted-foreground">Status</dt>
          <dd>
            <UserStatusBadge status={user.status} />
          </dd>
          <dt className="text-muted-foreground">Role</dt>
          <dd>
            <UserRoleBadge roleName={user.roleName} />
          </dd>
          <dt className="text-muted-foreground">Phone</dt>
          <dd>{user.phone ?? "—"}</dd>
          <dt className="text-muted-foreground">Branch</dt>
          <dd>{user.branch ?? "—"}</dd>
          <dt className="text-muted-foreground">Created</dt>
          <dd
            className="whitespace-nowrap"
            suppressHydrationWarning
          >
            {formatLocalDateTime(user.createdAt)}
          </dd>
          <dt className="text-muted-foreground">Updated</dt>
          <dd
            className="whitespace-nowrap"
            suppressHydrationWarning
          >
            {formatLocalDateTime(user.updatedAt)}
          </dd>
          {user.notes !== null && (
            <>
              <dt className="text-muted-foreground">Notes</dt>
              <dd className="col-span-2">{user.notes}</dd>
            </>
          )}
        </dl>

        <DialogFooter>
          <DialogClose render={<Button type="button" variant="outline" />}>
            Close
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
