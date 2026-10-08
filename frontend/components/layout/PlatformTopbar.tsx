"use client";

import { Building2, LogOut, Menu as MenuIcon } from "lucide-react";
import { usePathname } from "next/navigation";
import { useRouter } from "next/navigation";

import { resolvePlatformNavItem } from "@/components/layout/PlatformSidebar";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useAuthStore } from "@/stores/useAuthStore";

interface PlatformTopbarProps {
  /** `true` while the mobile navigation drawer is open. */
  isSidebarOpen: boolean;
  /** Opens the mobile navigation drawer. */
  onOpenSidebar: () => void;
}

/**
 * Platform Owner topbar.
 *
 * Holds the mobile navigation trigger, the current platform area, and the
 * account menu with logout.
 */
export function PlatformTopbar({ isSidebarOpen, onOpenSidebar }: PlatformTopbarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const logout = useAuthStore((state) => state.logout);
  const user = useAuthStore((state) => state.user);
  const currentArea = resolvePlatformNavItem(pathname)?.label ?? "Platform";

  async function handleLogout() {
    await logout();
    router.replace("/login");
    router.refresh();
  }

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

      <Building2 className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <p className="min-w-0 truncate text-sm font-semibold">{currentArea}</p>

      <div className="ml-auto flex items-center gap-2">
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button type="button" variant="ghost" className="h-9 gap-2 px-1.5" />
            }
          >
            <span
              className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-[0.7rem] font-semibold text-primary-foreground"
              aria-hidden="true"
            >
              {user?.name?.charAt(0).toUpperCase() ?? "P"}
            </span>
            <span className="hidden min-w-0 flex-col items-start leading-tight sm:flex" aria-hidden="true">
              <span className="w-full truncate text-xs font-medium">{user?.name ?? "Platform Owner"}</span>
              <span className="w-full truncate text-[0.7rem] font-normal text-muted-foreground">
                Platform Owner
              </span>
            </span>
            <span className="sr-only">Platform Owner account menu</span>
          </DropdownMenuTrigger>

          <DropdownMenuContent align="end" sideOffset={6} className="w-56">
            <div className="px-1.5 py-1">
              <p className="truncate text-sm font-medium">{user?.name ?? "Platform Owner"}</p>
              <p className="truncate text-xs text-muted-foreground">{user?.email ?? ""}</p>
              <p className="mt-0.5 truncate text-xs text-muted-foreground">Platform Owner</p>
            </div>

            <DropdownMenuSeparator />

            <DropdownMenuItem
              variant="destructive"
              onClick={() => {
                void handleLogout();
              }}
            >
              <LogOut aria-hidden="true" />
              Logout
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}