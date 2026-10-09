import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { apiClient } from "@/lib/axios";
import { setToken } from "@/lib/auth/token";

/**
 * Axios client regression tests for tab-scoped credentials.
 *
 * Two behaviours matter for independent multi-tab sessions:
 *
 *   1. Every request attaches the bearer token that is current *at the moment
 *      the request is sent*, read per-request from the tab's own
 *      `sessionStorage` — never cached on the instance. This is what lets two
 *      tabs carry two different credentials without racing.
 *   2. A `401` clears only the active tab's session. Because `clearSession()`
 *      now targets `sessionStorage`, a rejected request in one tab cannot wipe
 *      another tab's credential.
 *
 * The shared instance's adapter is swapped per-test so no real network call is
 * made; the interceptor chain (the code under test) still runs in full.
 */
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

/** A fresh tab-local sessionStorage for each test. */
function activateTab(): FakeStorage {
  const storage = new FakeStorage();
  Object.defineProperty(window, "sessionStorage", {
    value: storage,
    configurable: true,
    writable: true,
  });
  return storage;
}

/** Records the Authorization header the interceptor attached, then resolves. */
function capturingAdapter(): { seen: Array<string | undefined>; adapter: unknown } {
  const seen: Array<string | undefined> = [];
  const adapter = async (config: {
    headers?: Record<string, unknown>;
  }): Promise<unknown> => {
    const headers = (config.headers ?? {}) as Record<string, unknown>;
    seen.push(headers.Authorization as string | undefined);
    return { data: {}, status: 200, statusText: "OK", headers: {}, config };
  };
  return { seen, adapter };
}

beforeEach(() => {
  activateTab();
});

afterEach(() => {
  // Restore the default adapter so a later test is not left with a stub.
  apiClient.defaults.adapter = undefined;
  vi.restoreAllMocks();
});

describe("apiClient credential handling", () => {
  it("attaches the current tab's token as a Bearer header on every request", async () => {
    setToken("token-owner");
    const { seen, adapter } = capturingAdapter();
    apiClient.defaults.adapter = adapter as unknown as typeof apiClient.defaults.adapter;

    await apiClient.get("/auth/me");

    expect(seen[0]).toBe("Bearer token-owner");
  });

  it("reads the token per-request, so switching the tab token changes the header", async () => {
    const { seen, adapter } = capturingAdapter();
    apiClient.defaults.adapter = adapter as unknown as typeof apiClient.defaults.adapter;

    // Two tabs: the token is swapped between requests exactly as it would be if
    // each tab had its own sessionStorage.
    setToken("token-owner");
    await apiClient.get("/auth/me");
    setToken("token-cashier");
    await apiClient.get("/auth/me");

    expect(seen).toEqual(["Bearer token-owner", "Bearer token-cashier"]);
  });

  it("does not attach an Authorization header when the tab has no token", async () => {
    const { seen, adapter } = capturingAdapter();
    apiClient.defaults.adapter = adapter as unknown as typeof apiClient.defaults.adapter;

    await apiClient.get("/auth/me");

    expect(seen[0]).toBeUndefined();
  });

  it("clears only this tab's session on a 401", async () => {
    setToken("token-owner");
    const rejecting = async (): Promise<never> => {
      throw Object.assign(new Error("Unauthorized"), {
        isAxiosError: true,
        response: { status: 401, data: { message: "Unauthenticated" }, headers: {} },
        config: {},
      });
    };
    apiClient.defaults.adapter = rejecting as unknown as typeof apiClient.defaults.adapter;

    await expect(apiClient.get("/auth/me")).rejects.toMatchObject({ status: 401 });

    // The active tab's sessionStorage is wiped...
    expect(window.sessionStorage.getItem("pos.auth.token")).toBeNull();
    // ...and the shared localStorage was never the store in the first place.
    expect(window.localStorage.getItem("pos.auth.token")).toBeNull();
  });
});
