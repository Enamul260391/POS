import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

// `vi.mock` factories are hoisted above imports, so the shared spies must be
// created through `vi.hoisted` to avoid a temporal-dead-zone reference.
const { fetchCurrentUser, getToken, setCachedUser, clearSession } = vi.hoisted(() => ({
  fetchCurrentUser: vi.fn(),
  getToken: vi.fn<() => string | null>(),
  setCachedUser: vi.fn(),
  clearSession: vi.fn(),
}));

vi.mock("@/features/auth/services/authService", () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: (...args: unknown[]) => fetchCurrentUser(...(args as [])),
}));

vi.mock("@/lib/auth/token", () => ({
  getToken: () => getToken(),
  setToken: vi.fn(),
  setCachedUser: (u: unknown) => setCachedUser(u),
  getCachedUser: vi.fn(),
  clearSession: () => clearSession(),
}));

// These modules touch browser-only APIs at import time; stub them out.
vi.mock("@/lib/realtime", () => ({ stop: vi.fn() }));
vi.mock("@/stores/useProductStore", () => ({
  useCatalogueStore: { getState: () => ({ reset: vi.fn(), startRealtimeSync: vi.fn() }) },
}));
vi.mock("@/lib/axios", () => ({ onUnauthorized: vi.fn() }));

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

beforeEach(() => {
  fetchCurrentUser.mockReset();
  getToken.mockReset();
  setCachedUser.mockReset();
  clearSession.mockReset();
  useAuthStore.setState({ user: null, status: "unknown", isLoading: false, error: null });
});

describe("useAuthStore.restoreSession", () => {
  it("restores the Platform Owner session from the stored token", async () => {
    // Scenario 2 & 3: a fresh tab restores the owner session without a business role.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockResolvedValue(platformOwner);

    await useAuthStore.getState().restoreSession();

    expect(fetchCurrentUser).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().status).toBe("authenticated");
    expect(useAuthStore.getState().user?.isPlatformOwner).toBe(true);
  });

  it("is idempotent once a session is resolved (no duplicate /auth/me)", async () => {
    // Scenario 3: the root redirect restores the session and then hands off to
    // AuthGate; the second call must not fire another request.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockResolvedValue(platformOwner);

    await useAuthStore.getState().restoreSession();
    await useAuthStore.getState().restoreSession();

    expect(fetchCurrentUser).toHaveBeenCalledTimes(1);
  });

  it("marks the visitor unauthenticated when there is no token", async () => {
    // Scenario 8: no token means straight to the login flow.
    getToken.mockReturnValue(null);

    await useAuthStore.getState().restoreSession();

    expect(fetchCurrentUser).not.toHaveBeenCalled();
    expect(useAuthStore.getState().status).toBe("unauthenticated");
  });

  it("signs out on an expired/invalid session and clears storage", async () => {
    // Scenario 9: a rejected /auth/me must not grant access.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockRejectedValue(new Error("Unauthenticated."));

    await useAuthStore.getState().restoreSession();

    expect(clearSession).toHaveBeenCalled();
    expect(useAuthStore.getState().status).toBe("unauthenticated");
    expect(useAuthStore.getState().user).toBeNull();
  });
});
