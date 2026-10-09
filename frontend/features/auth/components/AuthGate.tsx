"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";

import { useAuthStore } from "@/stores/useAuthStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import { useIdleSession } from "@/features/auth/hooks/useIdleSession";

/** Where an unauthenticated visitor is sent. */
const LOGIN_ROUTE = "/login";

/**
 * Restores the session and gates the dashboard shell on it.
 *
 * Why this is a client component: the session lives in `sessionStorage` and the
 * API is bearer-authenticated, so the server has no way to know who is asking.
 * Every authenticated screen therefore renders behind this gate.
 *
 * Behaviour:
 *   - On mount, and whenever the status flips to `unauthenticated` (including
 *     the central `401` handler in `@/lib/axios`), the visitor is redirected to
 *     `/login`. The current location is passed along so the sign-in can send
 *     them back to where they were headed.
 *   - While the status is `unknown` the children stay hidden behind a neutral
 *     placeholder instead of flashing either the login form or the dashboard.
 *
 * Redirect-loop safety: the check is `status !== "authenticated"`, and the
 * login route is outside this gate, so signing in can never bounce back here.
 *
 * Realtime (Phase RT-1): this component is also the single place the business
 * channel is subscribed. It sits here because the subscription needs the
 * *authenticated* business id, which only exists once `restoreSession` has
 * answered — subscribing earlier would mean guessing a tenant, and subscribing
 * in several screens would mean several sockets. The effect is keyed on
 * `businessId`, so it runs once per tenant, and its cleanup unbinds the
 * listeners, which is what keeps React Strict Mode's double-invoke and route
 * changes from stacking duplicate handlers.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const router = useRouter();
  const status = useAuthStore((state) => state.status);
  const businessId = useAuthStore((state) => state.user?.businessId ?? null);
  const restoreSession = useAuthStore((state) => state.restoreSession);
  const startRealtimeSync = useCatalogueStore((state) => state.startRealtimeSync);

  // Per-tab idle logout + live work-state persistence. Keyed on the authenticated
  // user inside the hook, so it is inert until a session exists and is torn down
  // on sign-out — exactly one timer/subscription per tab, never duplicated.
  useIdleSession();

  useEffect(() => {
    // Only worth asking the API when a token exists; `restoreSession` short
    // circuits otherwise, but skipping the call keeps the effect honest.
    void restoreSession();
  }, [restoreSession]);

  useEffect(() => {
    if (status !== "unauthenticated") {
      return;
    }
    const { pathname, search } = window.location;
    if (pathname === LOGIN_ROUTE) {
      return;
    }
    const next = `${pathname}${search}`;
    router.replace(next === "/" ? LOGIN_ROUTE : `${LOGIN_ROUTE}?next=${encodeURIComponent(next)}`);
  }, [status, router]);

  useEffect(() => {
    // No authenticated tenant yet: nothing to subscribe to, and guessing a
    // channel name here would be exactly the mistake the backend refuses.
    if (status !== "authenticated" || businessId === null) {
      return;
    }

    return startRealtimeSync(businessId);
  }, [status, businessId, startRealtimeSync]);

  if (status !== "authenticated") {
    return (
      <div
        className="flex flex-1 items-center justify-center p-6"
        role="status"
        aria-live="polite"
      >
        <p className="text-sm text-muted-foreground">Checking your session…</p>
      </div>
    );
  }

  return children;
}