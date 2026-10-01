import type { LucideIcon } from "lucide-react";
import Link from "next/link";
import { cn } from "cn";

/**
 * One entry of the dashboard navigation.
 *
 * The type lives next to the item because it describes the item's own contract:
 * `Sidebar` owns the data, `SidebarItem` renders it and `Topbar` reads it to
 * label the current application area.
 */
export interface DashboardNavItem {
  /** Human readable label, e.g. "Inventory". */
  label: string;
  /** Route the entry points to, e.g. "/inventory". */
  href: string;
  /** Lucide icon rendered in front of the label. */
  icon: LucideIcon;
}

interface SidebarItemProps {
  /** Entry to render. */
  item: DashboardNavItem;
  /** `true` when the current route is this entry or one of its children. */
  isActive: boolean;
  /** Called on click; the mobile drawer uses it to close itself. */
  onNavigate?: () => void;
}

/**
 * A single navigation link.
 *
 * Intentionally presentational: the active route is resolved once by `Sidebar`
 * and passed in, so twelve entries do not each subscribe to the router. The
 * active entry is marked with colour *and* `aria-current="page"`, so it is
 * visible for sighted users and announced by screen readers. Keyboard focus is
 * shown with the shared ring token.
 */
export function SidebarItem({ item, isActive, onNavigate }: SidebarItemProps) {
  const Icon = item.icon;

  return (
    <li>
      <Link
        href={item.href}
        onClick={onNavigate}
        aria-current={isActive ? "page" : undefined}
        className={cn(
          "flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors outline-none",
          "focus-visible:ring-3 focus-visible:ring-sidebar-ring/50",
          isActive
            ? "bg-sidebar-primary text-sidebar-primary-foreground"
            : "text-sidebar-foreground/75 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
        )}
      >
        <Icon className="size-4 shrink-0" aria-hidden="true" />
        <span className="truncate">{item.label}</span>
      </Link>
    </li>
  );
}
