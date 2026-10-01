"use client";

import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

import { canAccessPath } from "@/lib/permissions/capabilities";

/**
 * Renders "You are not permitted" instead of a module the user cannot open.
 *
 * Deliberately a *blank* page: no table, no filters, no retry button and not
 * even the screen's own heading, because everything on such a page is content
 * the principal is not allowed to see and a half-drawn table is exactly the
 * confusion this replaces. The message is the whole render.
 *
 * Where it sits: the dashboard layout, so one gate covers every module and a new
 * page cannot forget it. The check is client-side purely to keep the user out of
 * a screen they hold no rights to; the API remains the authority and still
 * refuses the request itself, so this is a courtesy rather than the security
 * boundary.
 */
export function PermissionGate({ children }: { children: ReactNode }) {
  const pathname = usePathname();

  if (!canAccessPath(pathname)) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center p-6">
        <p className="text-sm text-muted-foreground">You are not permitted</p>
      </div>
    );
  }

  return children;
}