"use client";

import { Bell, Menu as MenuIcon, Search } from "lucide-react";
import { usePathname } from "next/navigation";

import { resolveDashboardNavItem } from "@/components/layout/Sidebar";
import { UserMenu } from "@/components/layout/UserMenu";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { env } from "@/lib/env";

interface TopbarProps {
  /** `true` while the mobile navigation drawer is open. */
  isSidebarOpen: boolean;
  /** Opens the mobile navigation drawer. */
  onOpenSidebar: () => void;
}

/**
 * Application topbar.
 *
 * Holds the mobile navigation trigger, the current application area, a search
 * placeholder, notifications and the account menu. It is sticky so it stays
 * reachable while page content scrolls, and it reads the same navigation data
 * as the sidebar to label the current area — no duplicated route table.
 *
 * The search field is deliberately a disabled placeholder: global search is a
 * later phase, and a control that silently does nothing would be worse.
 * The area label is a paragraph, not a heading, because the page itself owns
 * the `h1`.
 */
export function Topbar({ isSidebarOpen, onOpenSidebar }: TopbarProps) {
  const pathname = usePathname();
  const currentArea = resolveDashboardNavItem(pathname)?.label ?? env.appName;

  return (
    <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-1.5 border-b border-border bg-background px-2 sm:gap-2 sm:px-4">
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="lg:hidden"
        onClick={onOpenSidebar}
        aria-label="Open navigation menu"
        aria-haspopup="dialog"
        aria-expanded={isSidebarOpen}
      >
        <MenuIcon aria-hidden="true" />
      </Button>

      <p className="min-w-0 truncate text-sm font-semibold">{currentArea}</p>

      <div className="relative ml-auto hidden w-56 items-center md:flex lg:w-72">
        <Search
          className="pointer-events-none absolute left-2.5 size-4 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          type="search"
          className="pl-8"
          placeholder="Search products, sales, customers..."
          aria-label="Search"
          disabled
        />
      </div>

      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="ml-auto md:ml-0"
        aria-label="Notifications"
      >
        <Bell aria-hidden="true" />
      </Button>

      <UserMenu />
    </header>
  );
}
