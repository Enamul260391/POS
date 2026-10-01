"use client";

import { useState, type ReactNode } from "react";

import { AuthGate } from "@/features/auth/components/AuthGate";
import { PermissionGate } from "@/components/auth/PermissionGate";
import { Sidebar } from "@/components/layout/Sidebar";
import { Topbar } from "@/components/layout/Topbar";

/**
 * Dashboard application shell (Phase 4).
 *
 *   ┌──────────────┬────────────────────────────┐
 *   │              │          Topbar            │
 *   │   Sidebar    ├────────────────────────────┤
 *   │              │        Page content        │
 *   └──────────────┴────────────────────────────┘
 *
 * The layout is a client component because it owns the only piece of shared
 * shell state: whether the mobile navigation drawer is open. `Sidebar` and
 * `Topbar` are siblings, so their closest common parent has to hold it - that
 * keeps the store, the router and the pages free of shell concerns.
 *
 * Page content arrives through `children` and is therefore still rendered on the
 * server: marking the shell as a client boundary does not turn the pages inside
 * it into client components.
 *
 * Desktop: the sidebar is fixed to the viewport (`lg:fixed`, `w-64`) and the
 * content column reserves exactly that width with `lg:pl-64`, so only the page
 * scrolls. Keep those two values in sync when the rail width changes.
 * Mobile: the sidebar becomes a drawer (see `Sidebar`) and the topbar exposes the
 * trigger.
 */
export default function DashboardLayout({ children }: { children: ReactNode }) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);

  return (
    <AuthGate>
      <div className="flex min-w-0 flex-1 flex-col">
        <Sidebar open={isSidebarOpen} onOpenChange={setIsSidebarOpen} />

        <div className="flex min-w-0 flex-1 flex-col lg:pl-64">
          <Topbar isSidebarOpen={isSidebarOpen} onOpenSidebar={() => setIsSidebarOpen(true)} />

          <main className="flex min-w-0 flex-1 flex-col px-3 py-4 sm:px-4 lg:px-6">
            {/* One gate for every module: a page the signed-in user has no
                capability for renders as a blank "You are not permitted" page
                instead of mounting a screen whose requests would all fail. */}
            <PermissionGate>{children}</PermissionGate>
          </main>
        </div>
      </div>
    </AuthGate>
  );
}
