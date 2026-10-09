import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { RootRedirect } from "@/features/auth/components/RootRedirect";
import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

// `vi.mock` factories are hoisted above imports, so the shared spies must be
// created through `vi.hoisted` to avoid a temporal-dead-zone reference.
const { replace, refresh, getToken, fetchCurrentUser } = vi.hoisted(() => ({
  replace: vi.fn(),
  refresh: vi.fn(),
  getToken: vi.fn<() => string | null>(),
  fetchCurrentUser: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, refresh }),
}));

// `RootRedirect` restores the session on mount; the token and the /auth/me call
// are stubbed per-test to drive the status from `unknown` to its resolved value.
vi.mock("@/features/auth/services/authService", () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: (...args: unknown[]) => fetchCurrentUser(...(args as [])),
}));

vi.mock("@/lib/auth/token", () => ({
  getToken: () => getToken(),
  setToken: vi.fn(),
  setCachedUser: vi.fn(),
  getCachedUser: vi.fn(),
  clearSession: vi.fn(),
}));

vi.mock("@/lib/realtime", () => ({ stop: vi.fn() }));
vi.mock("@/stores/useProductStore", () => ({
  useCatalogueStore: { getState: () => ({ reset: vi.fn(), startRealtimeSync: vi.fn() }) },
}));
vi.mock("@/lib/axios", () => ({ onUnauthorized: vi.fn() }));

// A Platform Owner has no business role and an empty tenant capability list;
// the dashboard access must still resolve through the platform identity alone.
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
  capabilities: ["sales.create", "sales.view"],
};

/** A promise that never settles, used to hold the status at `unknown`. */
function pending<T>(): Promise<T> {
  return new Promise<T>(() => {});
}

beforeEach(() => {
  replace.mockClear();
  refresh.mockClear();
  fetchCurrentUser.mockReset();
  getToken.mockReset();
  useAuthStore.setState({ status: "unknown", user: null, isLoading: false, error: null });
});

afterEach(() => {
  useAuthStore.setState({ status: "unknown", user: null, isLoading: false });
});

describe("RootRedirect (/)", () => {
  it("sends an authenticated Platform Owner to /platform, not the business UI", async () => {
    // Scenario 1: opening / in a fresh tab as the Platform Owner.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockResolvedValue(platformOwner);

    render(<RootRedirect />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/platform"));
    expect(replace).not.toHaveBeenCalledWith("/pos");
  });

  it("sends an authenticated Business User to /pos", async () => {
    // Scenario 6: a business user opening / still lands on the business entry.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockResolvedValue(businessUser);

    render(<RootRedirect />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/pos"));
    expect(replace).not.toHaveBeenCalledWith("/platform");
  });

  it("does not redirect while the session is still resolving", async () => {
    // Scenario 3: no premature redirect into either shell before resolution.
    getToken.mockReturnValue("token-123");
    fetchCurrentUser.mockReturnValue(pending<AuthUser>());

    render(<RootRedirect />);

    // The neutral placeholder is shown instead of a dashboard or login form.
    expect(screen.getByText("Checking your session…")).toBeDefined();
    expect(replace).not.toHaveBeenCalled();
  });

  it("sends an unauthenticated visitor to /login", async () => {
    // Scenario 8: the login flow is preserved for anonymous visitors.
    getToken.mockReturnValue(null);

    render(<RootRedirect />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  });
});

