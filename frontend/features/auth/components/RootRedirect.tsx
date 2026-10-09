"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { LOGIN_ROUTE, landingRouteFor } from "@/features/auth/lib/routing";
import { useAuthStore } from "@/stores/useAuthStore";

/**
 * `/` — the authenticated entry point.
 *
 * The application has no public landing page, and the session is a bearer token
 * in `sessionStorage` with no server-side middleware, so the *server* cannot know
 * which account is asking. A server-side `redirect("/pos")` therefore sends
 * every visitor — including the Platform Owner — into the Business User
 * dashboard, where the owner authenticates but holds no tenant role and is
 * refused ("You are not permitted") on every business module.
 *
 * This component fixes that by deciding the destination *after* the session has
 * been restored and the account type is known:
 *
 *   - while the status is `unknown` a neutral placeholder is shown, so nothing
 *     is rendered or redirected into the wrong shell before the account type is
 *     resolved;
 *   - an authenticated Platform Owner is sent to the Platform Owner landing
 *     route (`/platform`) and a Business User to `/pos`;
 *   - an unauthenticated visitor is sent to `/login`, preserving the login flow.
 *
 * It never grants a capability: `PermissionGate` and the API still enforce what
 * each principal may actually open.
 */
export function RootRedirect() {
  const router = useRouter();
  const status = useAuthStore((state) => state.status);
  const isPlatformOwner = useAuthStore((state) => state.user?.isPlatformOwner ?? false);
  const restoreSession = useAuthStore((state) => state.restoreSession);

  // The root route is not wrapped in `AuthGate`, so the session is restored
  // here. `restoreSession` is a no-op once a session is already resolved, which
  // keeps the redirect that follows it from firing a second `/auth/me` request.
  useEffect(() => {
    void restoreSession();
  }, [restoreSession]);

  useEffect(() => {
    if (status === "unknown") {
      // Session still resolving: do not redirect into any shell yet.
      return;
    }

    router.replace(status === "authenticated" ? landingRouteFor(isPlatformOwner) : LOGIN_ROUTE);
  }, [status, isPlatformOwner, router]);

  return (
    <div className="flex flex-1 items-center justify-center p-6" role="status" aria-live="polite">
      <p className="text-sm text-muted-foreground">Checking your session…</p>
    </div>
  );
}
