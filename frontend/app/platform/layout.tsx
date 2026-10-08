"use client";

import { useState, type ReactNode } from "react";

import { AuthGate } from "@/features/auth/components/AuthGate";
import { PermissionGate } from "@/components/auth/PermissionGate";
import { PlatformSidebar } from "@/components/layout/PlatformSidebar";
import { PlatformTopbar } from "@/components/layout/PlatformTopbar";

/**
 * Platform Owner application shell.
 *
 * ┌──────────────┬────────────────────────────┐
 * │              │          Topbar            │
 * │   Sidebar    ├────────────────────────────┤
 * │              │        Page content        │
 * └──────────────┴────────────────────────────┘
 *
 * The layout is a client component because it owns the shared shell state:
 * whether the mobile navigation drawer is open. `PlatformSidebar` and
 * `PlatformTopbar` are siblings, so their closest common parent has to hold it.
 *
 * Page content arrives through `children` and is therefore still rendered on the
 * server: marking the shell as a client boundary does not turn the pages inside
 * it into client components.
 *
 * Desktop: the sidebar is fixed to the viewport (`lg:fixed`, `w-55`) and the
 * content column reserves exactly that width with `lg:pl-55`, so only the page
 * scrolls. Mobile: the sidebar becomes a drawer and the topbar exposes the
 * trigger.
 */
export default function PlatformLayout({ children }: { children: ReactNode }) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);

  return (
    <AuthGate>
      <div className="flex min-w-0 flex-1 flex-col">
        <PlatformSidebar open={isSidebarOpen} onOpenChange={setIsSidebarOpen} />

        <div className="flex min-w-0 flex-1 flex-col lg:pl-55">
          <PlatformTopbar isSidebarOpen={isSidebarOpen} onOpenSidebar={() => setIsSidebarOpen(true)} />

          <main className="flex min-w-0 flex-1 flex-col px-3 py-4 sm:px-4 lg:px-6">
            <PermissionGate>{children}</PermissionGate>
          </main>
        </div>
      </div>
    </AuthGate>
  );
}