import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * Tab-scoping regression tests for the Sanctum token store.
 *
 * The whole multi-account-in-separate-tabs requirement rests on one fact: the
 * token lives in `sessionStorage`, which each browser tab owns independently,
 * and *not* in `localStorage`, which every tab of the origin shares. If someone
 * ever changes `lib/auth/token.ts` back to `localStorage`, these tests fail.
 *
 * To model two tabs within jsdom (which has a single `window`), each "tab" is
 * represented by its own independent `Storage` implementation, assigned to
 * `window.sessionStorage` in turn — exactly how a real browser exposes a
 * different `sessionStorage` to each tab while the code reads whichever one it
 * is running in.
 */
import {
  clearSession,
  getCachedUser,
  getToken,
  setCachedUser,
  setToken,
} from "@/lib/auth/token";

/** A minimal, independent in-memory `Storage` used to model one tab. */
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

/** Points the module's `window.sessionStorage` at a fresh tab-local store. */
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
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("auth token storage is tab-scoped (sessionStorage)", () => {
  it("round-trips a token through sessionStorage", () => {
    setToken("token-owner");
    expect(window.sessionStorage.getItem("pos.auth.token")).toBe(JSON.stringify("token-owner"));
    expect(getToken()).toBe("token-owner");
  });

  it("never writes the token to the shared localStorage", () => {
    // The core regression: a shared localStorage token is what let one tab's
    // login overwrite another tab's identity.
    setToken("token-owner");

    expect(window.localStorage.getItem("pos.auth.token")).toBeNull();
    expect(window.localStorage.getItem("pos.auth.user")).toBeNull();
  });

  it("treats a blank/null token as signed out", () => {
    setToken("token-owner");
    setToken(null);
    expect(getToken()).toBeNull();
    setToken("   ");
    expect(getToken()).toBeNull();
  });

  it("treats corrupted JSON as absent rather than throwing", () => {
    window.sessionStorage.setItem("pos.auth.token", "{not json");
    expect(getToken()).toBeNull();
  });

  it("caches and reads the user snapshot from sessionStorage only", () => {
    const user = { id: 1, email: "owner@example.test" };
    setCachedUser(user);

    expect(window.localStorage.getItem("pos.auth.user")).toBeNull();
    expect(getCachedUser()).toEqual(user);
  });

  it("clearSession removes only the active tab's token and user", () => {
    setToken("token-owner");
    setCachedUser({ id: 1 });

    clearSession();

    expect(getToken()).toBeNull();
    expect(getCachedUser()).toBeNull();
  });

  it("keeps two tabs fully independent (different accounts, no cross-talk)", () => {
    // Scenario A/B at the storage layer: each tab owns its own sessionStorage.
    const tab1 = activateTab();
    setToken("token-owner");
    setCachedUser({ id: 1, email: "owner@example.test" });

    // Tab 2 is a brand-new browsing context: it must see nothing from tab 1.
    activateTab();
    // Tab 2 is a brand-new browsing context: it must see nothing from tab 1.
    expect(getToken()).toBeNull();
    expect(getCachedUser()).toBeNull();

    // Tab 2 signs in as a different account.
    setToken("token-cashier");
    setCachedUser({ id: 2, email: "cashier@example.test" });

    // Logging out of tab 2 must not touch tab 1's credential.
    clearSession();
    expect(getToken()).toBeNull();

    // Switching back to tab 1: its session is untouched.
    Object.defineProperty(window, "sessionStorage", {
      value: tab1,
      configurable: true,
      writable: true,
    });
    expect(getToken()).toBe("token-owner");
    expect(getCachedUser()).toEqual({ id: 1, email: "owner@example.test" });
  });
});
