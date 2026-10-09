import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

/**
 * Store-level, tab-isolation regression tests.
 *
 * Unlike `useAuthStore.test.ts` (which mocks `@/lib/auth/token` to unit-test the
 * state machine), this file uses the **real** token module so the whole path —
 * login writes a token, `/auth/me` restores it, logout clears it — runs against
 * the actual tab-scoped `sessionStorage`. Only the network service is stubbed.
 *
 * Two tabs are modelled by swapping `window.sessionStorage` between two
 * independent stores, exactly as a real browser gives each tab its own.
 */
const { loginRequest, logoutRequest, fetchCurrentUser } = vi.hoisted(() => ({
  loginRequest: vi.fn(),
  logoutRequest: vi.fn(),
  fetchCurrentUser: vi.fn(),
}));

vi.mock("@/features/auth/services/authService", () => ({
  login: (...args: unknown[]) => loginRequest(...args),
  logout: (...args: unknown[]) => logoutRequest(...args),
  me: (...args: unknown[]) => fetchCurrentUser(...args),
}));

// Realtime and the catalogue store touch browser-only APIs at import time.
vi.mock("@/lib/realtime", () => ({ stop: vi.fn() }));
vi.mock("@/stores/useProductStore", () => ({
  useCatalogueStore: { getState: () => ({ reset: vi.fn(), startRealtimeSync: vi.fn() }) },
}));

const platformOwner: AuthUser = {
  id: 1,
  businessId: null,
  isPlatformOwner: true,
  name: "Platform Owner",
  email: "owner@example.test",
  createdAt: "2026-01-01T00:00:00Z",
  roles: [],
  capabilities: [],
};

const businessUser: AuthUser = {
  id: 2,
  businessId: 9,
  isPlatformOwner: false,
  name: "Cashier",
  email: "cashier@example.test",
  createdAt: "2026-01-01T00:00:00Z",
  roles: ["cashier"],
  capabilities: ["sales.create"],
};

class FakeStorage implements Storage {
  private map = new Map<string, string>();
  get length(): number {
    return this.map.size;
  }
  clear(): void {
    this.map.clear();
  }
  getItem(key: string): string | null {
    return this.map.has(key) ? (this.map.get(key) as string) : null;
  }
  key(index: number): string | null {
    return Array.from(this.map.keys())[index] ?? null;
  }
  removeItem(key: string): void {
    this.map.delete(key);
  }
  setItem(key: string, value: string): void {
    this.map.set(key, String(value));
  }
}

function activateTab(): FakeStorage {
  const storage = new FakeStorage();
  Object.defineProperty(window, "sessionStorage", {
    value: storage,
    configurable: true,
    writable: true,
  });
  return storage;
}

beforeEach(() => {
  activateTab();
  loginRequest.mockReset();
  logoutRequest.mockReset().mockResolvedValue(undefined);
  fetchCurrentUser.mockReset();
  useAuthStore.setState({ user: null, status: "unknown", isLoading: false, error: null, fieldErrors: {} });
});

describe("useAuthStore tab isolation", () => {
  it("login persists the token to this tab's sessionStorage only", async () => {
    loginRequest.mockResolvedValueOnce({ user: platformOwner, token: "token-owner" });

    await useAuthStore.getState().login({ email: "owner@example.test", password: "secret" });

    expect(window.sessionStorage.getItem("pos.auth.token")).toBe(JSON.stringify("token-owner"));
    expect(window.localStorage.getItem("pos.auth.token")).toBeNull();
    expect(useAuthStore.getState().status).toBe("authenticated");
  });

  it("logging out in one tab does not clear another tab's session", async () => {
    // Tab 1 signs in.
    const tab1 = activateTab();
    loginRequest.mockResolvedValueOnce({ user: platformOwner, token: "token-owner" });
    await useAuthStore.getState().login({ email: "owner@example.test", password: "secret" });

    // Tab 2 signs in, then logs out.
    activateTab();
    loginRequest.mockResolvedValueOnce({ user: businessUser, token: "token-cashier" });
    await useAuthStore.getState().login({ email: "cashier@example.test", password: "secret" });
    await useAuthStore.getState().logout();

    // Tab 2 is signed out and only its own presented token was revoked.
    expect(useAuthStore.getState().status).toBe("unauthenticated");
    expect(window.sessionStorage.getItem("pos.auth.token")).toBeNull();
    expect(logoutRequest).toHaveBeenCalledTimes(1);

    // Tab 1's independent credential is untouched.
    expect(tab1.getItem("pos.auth.token")).toBe(JSON.stringify("token-owner"));
  });

  it("restoreSession restores the account that belongs to the active tab", async () => {
    loginRequest.mockResolvedValueOnce({ user: platformOwner, token: "token-owner" });
    await useAuthStore.getState().login({ email: "owner@example.test", password: "secret" });

    // Simulate a reload of the same tab: state is reset, sessionStorage persists.
    useAuthStore.setState({ user: null, status: "unknown" });
    fetchCurrentUser.mockResolvedValueOnce(platformOwner);

    await useAuthStore.getState().restoreSession();

    expect(fetchCurrentUser).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().user?.email).toBe("owner@example.test");
    expect(useAuthStore.getState().user?.isPlatformOwner).toBe(true);
  });

  it("does not authenticate a second tab as the first tab's account", async () => {
    // Tab 1 (owner) signs in.
    loginRequest.mockResolvedValueOnce({ user: platformOwner, token: "token-owner" });
    await useAuthStore.getState().login({ email: "owner@example.test", password: "secret" });

    // Tab 2 is a fresh context: a brand-new store instance with no token present,
    // so restore yields signed-out rather than silently inheriting tab 1's identity.
    activateTab();
    useAuthStore.setState({ user: null, status: "unknown" });
    await useAuthStore.getState().restoreSession();

    expect(useAuthStore.getState().status).toBe("unauthenticated");
    expect(useAuthStore.getState().user).toBeNull();
    expect(fetchCurrentUser).not.toHaveBeenCalled();
  });
});


afterEach(() => {
  vi.restoreAllMocks();
});
