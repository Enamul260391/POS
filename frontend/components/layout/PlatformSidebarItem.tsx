"use client";

import { memo } from "react";
import type { LucideIcon } from "lucide-react";
import Link from "next/link";

import { cn } from "cn";

/** One entry in the platform sidebar. */
export interface PlatformNavItem {
  label: string;
  href: string;
  icon: LucideIcon;
}

interface PlatformSidebarItemProps {
  item: PlatformNavItem;
  isActive: boolean;
  /** Called after a link click; only the mobile drawer passes it. */
  onNavigate?: () => void;
}

/**
 * Platform sidebar item.
 *
 * Uses `Link` for navigation so the router handles prefetching and scroll
 * restoration. The active state is computed by the parent from the current
 * pathname.
 */
export const PlatformSidebarItem = memo(function PlatformSidebarItem({
  item,
  isActive,
  onNavigate,
}: PlatformSidebarItemProps) {
  const Icon = item.icon;

  return (
    <li>
      <Link
        href={item.href}
        onClick={onNavigate}
        className={cn(
          "flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors outline-none",
          "focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50",
          isActive
            ? "bg-sidebar-primary text-sidebar-primary-foreground"
            : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
        )}
        aria-current={isActive ? "page" : undefined}
      >
        <Icon className="size-4 shrink-0" aria-hidden="true" />
        <span className="truncate">{item.label}</span>
      </Link>
    </li>
  );
});