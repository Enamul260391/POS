"use client";

import { useEffect } from "react";

import { startIdleWatch } from "@/lib/auth/idle";
import { saveWorkState } from "@/lib/pos/workState";
import { useAuthStore } from "@/stores/useAuthStore";
import { useCartStore } from "@/stores/useCartStore";

/**
 * Per-tab inactivity logout + live work-state persistence.
 *
 * Mounted once, inside `AuthGate`, for the authenticated shell — the single place
 * both the dashboard and platform layouts already share, so there is exactly one
 * idle timer and one cart-persistence subscription per tab (never duplicates).
 *
 * Behaviour:
 *   - While authenticated it subscribes to the cart store and writes the current
 *     work state on every change, so a page refresh never loses an in-progress
 *     sale and an idle sign-out always has a fresh snapshot to save.
 *   - It starts the idle watchdog; when the deadline is reached it performs an
 *     idle logout for *this tab's* account (revoking this tab's token, clearing
 *     this tab's session) and saves the recoverable state first.
 *
 * Isolation: this hook is per browsing context, so its timer, listeners and
 * saved state belong to this tab alone. It never reads or writes a shared
 * `localStorage` timestamp, so activity in one account's tab can never keep
 * another account's session alive. The effect is keyed on the authenticated
 * user, so a fresh login starts a brand-new watchdog and a stale timer can never
 * sign out the new account; on unmount or an account change the watchdog and the
 * subscription are torn down.
 */
export function useIdleSession(): void {
  const status = useAuthStore((state) => state.status);
  const userId = useAuthStore((state) => state.user?.id ?? null);
  const businessId = useAuthStore((state) => state.user?.businessId ?? null);
  const idleLogout = useAuthStore((state) => state.idleLogout);

  useEffect(() => {
    if (status !== "authenticated" || userId === null) {
      return;
    }

    // Live persistence: any cart change rewrites this principal's work state with
    // the current route, so recovery is always up to date. The guard is what
    // makes sign-out safe: `logout`, `idleLogout` and the central `401` handler
    // all empty the cart *after* flipping the auth state, but this subscription
    // stays attached until React re-renders and runs its cleanup — without the
    // check, `clearCart` would fire it and overwrite the snapshot idle logout
    // just saved (or re-create the record explicit logout deliberately
    // discarded). Zustand state is read synchronously, so the guard sees the
    // flipped session even before that re-render happens.
    const persist = (): void => {
      const auth = useAuthStore.getState();
      if (
        auth.status !== "authenticated" ||
        auth.user === null ||
        auth.user.id !== userId ||
        auth.user.businessId !== businessId
      ) {
        return;
      }
      if (typeof window !== "undefined") {
        saveWorkState(userId, businessId, window.location.pathname);
      }
    };
    const unsubscribe = useCartStore.subscribe(persist);

    const stopWatch = startIdleWatch(() => {
      void idleLogout(typeof window !== "undefined" ? window.location.pathname : "/");
    });

    return () => {
      unsubscribe();
      stopWatch();
    };
  }, [status, userId, businessId, idleLogout]);
}
