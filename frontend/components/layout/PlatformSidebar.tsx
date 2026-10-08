"use client";

import { Building2, LayoutDashboard, Store } from "lucide-react";
import { usePathname } from "next/navigation";
import { cn } from "cn";

import { PlatformSidebarItem, type PlatformNavItem } from "@/components/layout/PlatformSidebarItem";
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet";
import { env } from "@/lib/env";

/**
 * Platform Owner navigation entries, in sidebar order.
 */
export const PLATFORM_NAV_ITEMS: readonly PlatformNavItem[] = [
  { label: "Dashboard", href: "/platform/dashboard", icon: LayoutDashboard },
  { label: "Businesses", href: "/platform/businesses", icon: Building2 },
];

/**
 * `true` when a pathname is the entry itself or a nested route of it.
 */
function matchesPath(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`);
}

/**
 * Resolves the navigation entry a pathname belongs to.
 */
export function resolvePlatformNavItem(pathname: string): PlatformNavItem | undefined {
  return PLATFORM_NAV_ITEMS.find((item) => matchesPath(pathname, item.href));
}

interface PlatformSidebarNavProps {
  /** Called after a link click; only the mobile drawer passes it. */
  onNavigate?: () => void;
}

/** The navigation list itself. */
function PlatformSidebarNav({ onNavigate }: PlatformSidebarNavProps) {
  const pathname = usePathname();

  return (
    <nav aria-label="Platform navigation" className="flex-1 overflow-y-auto p-3">
      <ul className="flex flex-col gap-1">
{PLATFORM_NAV_ITEMS.map((item) => (
            <PlatformSidebarItem
              key={item.href}
              item={item}
              isActive={matchesPath(pathname, item.href)}
              onNavigate={onNavigate}
            />
          ))}
      </ul>
    </nav>
  );
}

/** Application mark shown at the top of the sidebar. */
function PlatformSidebarBrand() {
  return (
    <>
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
        <Store className="size-4" aria-hidden="true" />
      </span>
      <span className="truncate text-sm font-semibold tracking-tight">{env.appName} Platform</span>
    </>
  );
}

interface PlatformSidebarProps {
  /** `true` while the mobile drawer is open. Ignored from `lg` up. */
  open: boolean;
  /** Requests a new drawer state: backdrop click, Escape, close button or navigation. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Platform Owner navigation shell.
 *
 * Two presentations, one navigation list:
 * - `lg` and up: a fixed rail so only the page content scrolls.
 * - Below `lg`: an off-canvas drawer built on the shadcn `Sheet`.
 */
export function PlatformSidebar({ open, onOpenChange }: PlatformSidebarProps) {
  return (
    <>
      <aside
        className={cn(
          "hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-55 lg:flex-col",
          "border-r border-sidebar-border bg-sidebar text-sidebar-foreground",
        )}
      >
        <div className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border px-4">
          <PlatformSidebarBrand />
        </div>
        <PlatformSidebarNav />
      </aside>

      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent
          side="left"
          className="w-72 max-w-[80vw] gap-0 border-sidebar-border bg-sidebar p-0 text-sidebar-foreground lg:hidden"
        >
          <SheetTitle className="sr-only">Platform navigation</SheetTitle>
          <SheetDescription className="sr-only">
            All areas of the {env.appName} Platform.
          </SheetDescription>

          <div className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border px-4 pr-12">
            <PlatformSidebarBrand />
          </div>

          <PlatformSidebarNav onNavigate={() => onOpenChange(false)} />
        </SheetContent>
      </Sheet>
    </>
  );
}