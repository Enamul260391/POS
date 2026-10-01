"use client";

import { useState } from "react";
import { ChevronDown, Loader2, LogOut, Settings, UserRound } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

/** Wording shown under the name when the user holds no role. */
const NO_ROLE_LABEL = "No role";

/** "Store Admin" -> "SA"; single word names fall back to one letter. */
function getInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const first = words[0] ?? "";
  const last = words.length > 1 ? words[words.length - 1] ?? "" : "";

  return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase() || "?";
}

/**
 * Account menu.
 *
 * Everything a cashier needs about the session in one place: who is signed in,
 * shortcuts to profile and settings, and logout. Keyboard navigation, focus
 * handling, Escape and typeahead come from the dropdown primitive, while the
 * trigger stays a real button that also carries the user name, so the menu has a
 * meaningful accessible name on small screens where the name is hidden.
 *
 * Profile and settings link to routes that later phases create (the same
 * forward-looking approach as the sidebar).
 *
 * Phase 25 replaced the hard-coded placeholder session with the real one from
 * `useAuthStore` and wired the previously disabled Logout entry to
 * `POST /auth/logout` followed by a redirect to the login form. The visual
 * treatment is unchanged — only the `disabled`/`Soon` state of Logout is gone.
 *
 * Role label: `/auth/me` exposes role **slugs** (`admin`), not display names, so
 * the slug is shown as-is rather than guessed at. Frontend permission hints
 * only improve UX; Laravel remains the authority.
 */
export function UserMenu() {
  const router = useRouter();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  // The shell only renders behind `AuthGate`, so a user is always present; the
  // fallbacks keep the component safe to render in isolation.
  const sessionUser: AuthUser | null = user;
  const displayName = sessionUser?.name ?? "";
  const roleLabel =
    sessionUser === null || sessionUser.roles.length === 0
      ? NO_ROLE_LABEL
      : sessionUser.roles.join(", ");

  async function handleLogout() {
    if (isLoggingOut) {
      return;
    }
    setIsLoggingOut(true);
    await logout();
    router.replace("/login");
    router.refresh();
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            type="button"
            variant="ghost"
            className="h-9 gap-2 px-1.5"
          />
        }
      >
        <span
          className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-[0.7rem] font-semibold text-primary-foreground"
          aria-hidden="true"
        >
          {getInitials(displayName)}
        </span>
        <span
          className="hidden min-w-0 flex-col items-start leading-tight sm:flex"
          aria-hidden="true"
        >
          <span className="w-full truncate text-xs font-medium">{displayName}</span>
          <span className="w-full truncate text-[0.7rem] font-normal text-muted-foreground">
            {roleLabel}
          </span>
        </span>
        <span className="sr-only">{displayName} account menu</span>
        <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" sideOffset={6} className="w-60">
        {/* User information: plain markup, because this block is not a menu item. */}
        <div className="px-1.5 py-1">
          <p className="truncate text-sm font-medium">{displayName}</p>
          <p className="truncate text-xs text-muted-foreground">{sessionUser?.email ?? ""}</p>
          <p className="mt-0.5 truncate text-xs text-muted-foreground">{roleLabel}</p>
        </div>

        <DropdownMenuSeparator />

        <DropdownMenuGroup>
          <DropdownMenuItem render={<Link href="/profile" />}>
            <UserRound aria-hidden="true" />
            Profile
          </DropdownMenuItem>
          <DropdownMenuItem render={<Link href="/settings" />}>
            <Settings aria-hidden="true" />
            Settings
          </DropdownMenuItem>
        </DropdownMenuGroup>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          variant="destructive"
          disabled={isLoggingOut}
          onClick={() => {
            void handleLogout();
          }}
        >
          {isLoggingOut ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <LogOut aria-hidden="true" />
          )}
          {isLoggingOut ? "Signing out…" : "Logout"}
          {isLoggingOut ? null : <DropdownMenuShortcut>Sign out</DropdownMenuShortcut>}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
