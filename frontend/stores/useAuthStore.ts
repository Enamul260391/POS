import { create } from "zustand";

import {
  login as loginRequest,
  logout as logoutRequest,
  me as fetchCurrentUser,
} from "@/features/auth/services/authService";
import { onUnauthorized } from "@/lib/axios";
import {
  clearSession,
  getCachedUser,
  getToken,
  setCachedUser,
  setToken,
} from "@/lib/auth/token";
import { stop as stopRealtime } from "@/lib/realtime";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { ApiError } from "@/types/api";
import type { AuthUser, LoginCredentials } from "@/types/auth";

/**
 * Authentication state (Phase 25).
 *
 * There was no auth store before this phase — the shell showed a hard-coded
 * placeholder user — so this is a new store rather than an extension. It owns
 * exactly what a session needs and nothing more:
 *
 *   user        the principal returned by `/auth/login` or `/auth/me`
 *   isLoading   a login, logout or session restore is in flight
 *   error       the last failure, already phrased for display
 *
 * Deliberately absent: the token. It lives in `@/lib/auth/token` and is read
 * by the Axios request interceptor, so no component can accidentally leak it
 * into a render, and there is exactly one copy of the truth.
 *
 * Session restore: `restoreSession` reads the stored token and asks
 * `/auth/me` who it belongs to. A `401` (or any failure) leaves the app
 * signed out rather than half-authenticated.
 */

/** Lifecycle of the authentication state, used by the UI to gate rendering. */
export type AuthStatus = "unknown" | "authenticated" | "unauthenticated";

export interface AuthStore {
  /** The signed-in principal, or `null` while signed out. */
  user: AuthUser | null;
  /**
   * `unknown` until the first `restoreSession` resolves.
   *
   * The distinction matters: rendering the login form while a reload is still
   * in flight would flash it at an authenticated user.
   */
  status: AuthStatus;
  /** `true` while a login, logout or restore request is in flight. */
  isLoading: boolean;
  /** Last failure, phrased for display; `null` when there is none. */
  error: string | null;
  /** Field-level validation messages from a `422` login response. */
  fieldErrors: Record<string, string[]>;

  /** Signs in and persists the session. Rejects so the form can react. */
  login: (credentials: LoginCredentials) => Promise<AuthUser>;
  /** Revokes the token server-side and clears local state. Never rejects. */
  logout: () => Promise<void>;
  /** Re-establishes the session from the stored token. Never rejects. */
  restoreSession: () => Promise<void>;
  /** Clears the current error, e.g. when the login form is edited again. */
  clearError: () => void;
  /** `true` when the principal holds a role with one of these slugs. */
  hasRole: (slugs: readonly string[]) => boolean;
}

/** Extracts a display-safe message from a rejected service call. */
function messageOf(error: unknown, fallback: string): string {
  if (typeof error === "object" && error !== null && "message" in error) {
    const message = (error as ApiError).message;
    if (typeof message === "string" && message.trim() !== "") {
      return message;
    }
  }
  return fallback;
}

/** Field-level messages of a rejected call, empty for non-validation errors. */
function fieldErrorsOf(error: unknown): Record<string, string[]> {
  if (typeof error === "object" && error !== null && "validationErrors" in error) {
    return (error as ApiError).validationErrors;
  }
  return {};
}

export const useAuthStore = create<AuthStore>()((set, get) => ({
  // Seeded from the cached snapshot so the shell paints with a name on the
  // very first render; `restoreSession` then confirms it against the API.
  user: getCachedUser<AuthUser>(),
  status: getToken() === null ? "unauthenticated" : "unknown",
  isLoading: false,
  error: null,
  fieldErrors: {},

  async login(credentials) {
    set({ isLoading: true, error: null, fieldErrors: {} });

    try {
      const { user, token } = await loginRequest(credentials);
      // Persist before flipping the status: the next request must already be
      // able to carry the new token.
      setToken(token);
      setCachedUser(user);
      set({ user, status: "authenticated", isLoading: false });
      return user;
    } catch (error) {
      set({
        isLoading: false,
        status: "unauthenticated",
        error: messageOf(error, "Unable to sign in. Please try again."),
        fieldErrors: fieldErrorsOf(error),
      });
      throw error;
    }
  },

  async logout() {
    set({ isLoading: true });

    // Revoke first so a still-valid token is not left usable server-side; the
    // local session is cleared regardless, because a network failure must never
    // strand the cashier in a session they asked to leave.
    try {
      await logoutRequest();
    } catch {
      // Intentionally ignored — see above.
    }

    clearSession();

    // Realtime teardown (Phase RT-1). The socket carries this tenant's private
    // subscription, so it must be closed on the way out — otherwise the browser
    // keeps a signed-in channel open for a session that no longer exists, and the
    // next user of this tab would inherit the previous tenant's membership until
    // it timed out. The catalogue is cleared too, so the next account never sees
    // the previous one's cached products.
    stopRealtime();
    void useCatalogueStore.getState().reset();

    set({ user: null, status: "unauthenticated", isLoading: false, error: null });
  },

  async restoreSession() {
    if (getToken() === null) {
      set({ user: null, status: "unauthenticated", isLoading: false });
      return;
    }

    set({ isLoading: true });

    try {
      const user = await fetchCurrentUser();
      setCachedUser(user);
      set({ user, status: "authenticated", isLoading: false, error: null });
    } catch (error) {
      // The Axios interceptor has already cleared storage on a 401, so all
      // that is left is to reflect the signed-out state in the UI.
      clearSession();
      set({
        user: null,
        status: "unauthenticated",
        isLoading: false,
        error: messageOf(error, "Your session has expired. Please sign in again."),
      });
    }
  },

  clearError() {
    set({ error: null, fieldErrors: {} });
  },

  hasRole(slugs) {
    const roles = get().user?.roles ?? [];
    return slugs.some((slug) => roles.includes(slug));
  },
}));

/**
 * Central `401` handling.
 *
 * Registered once, at module load, so an expired token clears the Zustand
 * state as well as the stored token. The redirect itself is *not* done here:
 * this module is also imported by server-rendered paths, and navigation must
 * go through the client shell (`AuthGate`) which owns routing.
 */
onUnauthorized(() => {
  // A 401 means the token is gone, so the realtime session is gone with it —
  // same reasoning as an explicit logout. Clearing the catalogue prevents the
  // next sign-in from briefly showing the previous account's products.
  stopRealtime();
  void useCatalogueStore.getState().reset();

  useAuthStore.setState({
    user: null,
    status: "unauthenticated",
    isLoading: false,
  });
});