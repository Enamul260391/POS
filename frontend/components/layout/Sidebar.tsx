"use client";

import {
  Boxes,
  ChartColumn,
  Handshake,
  LayoutDashboard,
  Package,
  ReceiptText,
    Settings,
  Shield,
  ShoppingCart,
  Store,
  Truck,
  UserCog,
  Users,
  Wallet,
  WalletCards,
} from "lucide-react";
import { usePathname } from "next/navigation";
import { cn } from "cn";

import { SidebarItem, type DashboardNavItem } from "@/components/layout/SidebarItem";
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet";
import { env } from "@/lib/env";

/**
 * Every area of the application, in sidebar order.
 *
 * This is the single source of truth for navigation: the sidebar renders it, the
 * topbar uses it to label the current area and the active state is derived from
 * it. The routes themselves are created in later phases, so the links are
 * intentionally ahead of the pages.
 */
export const DASHBOARD_NAV_ITEMS: readonly DashboardNavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: LayoutDashboard },
  { label: "POS", href: "/pos", icon: ShoppingCart },
  { label: "Products", href: "/products", icon: Package },
  { label: "Inventory", href: "/inventory", icon: Boxes },
  { label: "Sales", href: "/sales", icon: ReceiptText },
  { label: "Purchases", href: "/purchases", icon: Truck },
  { label: "Customers", href: "/customers", icon: Users },
  { label: "Suppliers", href: "/suppliers", icon: Handshake },
  { label: "Expenses", href: "/expenses", icon: Wallet },
  { label: "Cash Drawer", href: "/cash-drawer", icon: WalletCards },
  { label: "Reports", href: "/reports", icon: ChartColumn },
    { label: "Users", href: "/users", icon: UserCog },
  { label: "Roles", href: "/roles", icon: Shield },
  { label: "Settings", href: "/settings", icon: Settings },
];

/**
 * `true` when a pathname is the entry itself or a nested route of it.
 *
 * Prefix matching keeps a section highlighted while a detail screen is open
 * (`/products/42` still highlights "Products").
 */
function matchesPath(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`);
}

/**
 * Resolves the navigation entry a pathname belongs to.
 *
 * Used for the active sidebar entry and for the topbar area label, so both stay
 * in sync without duplicating the route list.
 */
export function resolveDashboardNavItem(pathname: string): DashboardNavItem | undefined {
  return DASHBOARD_NAV_ITEMS.find((item) => matchesPath(pathname, item.href));
}

interface SidebarNavProps {
  /** Called after a link click; only the mobile drawer passes it. */
  onNavigate?: () => void;
}

/**
 * The navigation list itself.
 *
 * Shared by the fixed desktop rail and the mobile drawer, so both always show
 * the same entries and only the surrounding container differs.
 */
function SidebarNav({ onNavigate }: SidebarNavProps) {
  const pathname = usePathname();

  return (
    <nav aria-label="Main navigation" className="flex-1 overflow-y-auto p-3">
      <ul className="flex flex-col gap-1">
        {DASHBOARD_NAV_ITEMS.map((item) => (
          <SidebarItem
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
function SidebarBrand() {
  return (
    <>
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
        <Store className="size-4" aria-hidden="true" />
      </span>
      <span className="truncate text-sm font-semibold tracking-tight">{env.appName}</span>
    </>
  );
}

interface SidebarProps {
  /** `true` while the mobile drawer is open. Ignored from `lg` up. */
  open: boolean;
  /** Requests a new drawer state: backdrop click, Escape, close button or navigation. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Dashboard navigation shell.
 *
 * Two presentations, one navigation list:
 *
 * - `lg` and up: a fixed rail (`fixed` + `w-64`) so only the page content
 *   scrolls. The layout reserves the same width with `lg:pl-64`.
 * - Below `lg`: an off-canvas drawer built on the shadcn `Sheet`. Focus
 *   trapping, Escape handling, backdrop, scroll lock and `aria-modal`
 *   semantics come from the primitive, so no custom dialog code is needed here,
 *   and the close button is rendered by `SheetContent` itself.
 *
 * No animation beyond the sheet's own short slide, and no business logic: the
 * navigation only links to routes that later phases implement.
 */
export function Sidebar({ open, onOpenChange }: SidebarProps) {
  return (
    <>
      <aside
        className={cn(
          "hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-64 lg:flex-col",
          "border-r border-sidebar-border bg-sidebar text-sidebar-foreground",
        )}
      >
        <div className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border px-4">
          <SidebarBrand />
        </div>
        <SidebarNav />
      </aside>

      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent
          side="left"
          /* `lg:hidden` keeps the drawer out of the way if the window is resized
             to desktop width while it is open. */
          className="w-72 max-w-[80vw] gap-0 border-sidebar-border bg-sidebar p-0 text-sidebar-foreground lg:hidden"
        >
          {/* Screen reader label for the dialog; the visible brand sits below. */}
          <SheetTitle className="sr-only">Main navigation</SheetTitle>
          <SheetDescription className="sr-only">
            All areas of the {env.appName} application.
          </SheetDescription>

          {/* `pr-12` keeps the brand clear of the sheet's close button. */}
          <div className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border px-4 pr-12">
            <SidebarBrand />
          </div>

          <SidebarNav onNavigate={() => onOpenChange(false)} />
        </SheetContent>
      </Sheet>
    </>
  );
}

