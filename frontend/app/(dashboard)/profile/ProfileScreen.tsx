"use client";

import { formatLocalDateTime } from "@/lib/datetime";
import { useAuthStore } from "@/stores/useAuthStore";

/**
 * Read-only view of the signed-in principal. The dashboard layout already gates
 * this route behind `AuthGate`, so `user` is present when we render; the null
 * guard keeps TypeScript happy.
 */
export function ProfileScreen() {
  const user = useAuthStore((state) => state.user);

  if (!user) {
    return null;
  }

  return (
    <section className="space-y-4">
      <h2 className="text-lg font-semibold">Profile</h2>
      <dl className="grid grid-cols-4 gap-x-4 gap-y-2 text-sm">
        <dt className="text-muted-foreground">Name</dt>
        <dd className="font-medium">{user.name}</dd>
        <dt className="text-muted-foreground">Email</dt>
        <dd className="font-medium">{user.email}</dd>
        <dt className="text-muted-foreground">Roles</dt>
        <dd className="font-medium">{user.roles.join(", ") || "—"}</dd>
        <dt className="text-muted-foreground">Member since</dt>
        <dd className="font-medium">{formatLocalDateTime(user.createdAt)}</dd>
        {user.businessId !== null && (
          <>
            <dt className="text-muted-foreground">Business</dt>
            <dd className="font-medium">#{user.businessId}</dd>
          </>
        )}
        <dt className="text-muted-foreground">Platform owner</dt>
        <dd className="font-medium text-sm">
          {user.isPlatformOwner ? "Yes" : "No"}
        </dd>
      </dl>
    </section>
  );
}
