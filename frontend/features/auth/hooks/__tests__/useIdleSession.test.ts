import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useIdleSession } from "@/features/auth/hooks/useIdleSession";
import { getToken, setToken } from "@/lib/auth/token";
import { readWorkState } from "@/lib/pos/workState";
import { useAuthStore } from "@/stores/useAuthStore";
import { useCartStore } from "@/stores/useCartStore";
import type { AuthUser } from "@/types/auth";
import type { Product } from "@/types/product";

/**
 * `useIdleSession` — live work-state persistence and idle sign-out, exercised
 * with the **real** cart subscription. This matters: the failure this suite
 * pins down can only be observed while the hook is mounted, because the
 * regression is the persistence subscription firing on the cart clears that
 * sign-out performs *before* React has re-rendered and torn it down. Testing
 * the store actions without the hook would never see the write happen.
 *
 * Only the network (auth service) and the realtime module are stubbed; the
 * token module, cart store and work-state storage are the real ones, so the
 * whole save → sign-out → read-back path runs against the actual storage the
 * app uses.
 */
const { logoutRequest } = vi.hoisted(() => ({ logoutRequest: vi.fn() }));

vi.mock("@/features/auth/services/authService", () => ({
  login: vi.fn(),
  logout: (...args: unknown[]) => logoutRequest(...args),
  me: vi.fn(),
}));

// Realtime touches browser-only APIs at import time; stub it out.
vi.mock("@/lib/realtime", () => ({ stop: vi.fn(), bind: vi.fn(), isSubscribed: vi.fn() }));

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

function makeProduct(id: number, name: string): Product {
  return {
    id,
    name,
    sku: `SKU-${id}`,
    barcode: null,
    unit: "piece",
    image: null,
    status: "active",
    categoryId: null,
    categoryName: null,
    stock: 100,
    lowStockThreshold: 5,
    createdAt: "2026-01-01T00:00:00Z",
    updatedAt: "2026-01-01T00:00:00Z",
  };
}

/** Ids of the products on the saved lines, for readable assertions. */
function savedProductIds(): number[] {
  return (readWorkState(2, 9)?.items ?? []).map((line) => line.productId);
}

beforeEach(() => {
  window.sessionStorage.clear();
  useCartStore.getState().clearCart();
  logoutRequest.mockReset().mockResolvedValue(undefined);
  setToken("token-cashier");
  useAuthStore.setState({
    user: businessUser,
    status: "authenticated",
    isLoading: false,
    error: null,
    fieldErrors: {},
  });
});

afterEach(() => {
  // No auth-store reset here: `beforeEach` fully re-establishes the session
  // before the next render, and touching the store while this test's hook is
  // still mounted (the shared `cleanup` runs after this hook) would warn about
  // an update outside `act`. Cart first, then storage, so anything the still
  // attached persistence subscription writes during `clearCart` is wiped too —
  // the next `beforeEach` clears storage again regardless.
  useCartStore.getState().clearCart();
  window.sessionStorage.clear();
});

describe("useIdleSession", () => {
  it("persists the cart live while the principal is authenticated", () => {
    renderHook(() => useIdleSession());

    act(() => {
      useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    });

    const saved = readWorkState(2, 9);
    expect(saved).not.toBeNull();
    expect(saved?.items).toHaveLength(1);
    expect(saved?.items[0]?.productId).toBe(7);
  });

  it("keeps the snapshot restorable through an idle logout (clearCart must not clobber it)", async () => {
    // Regression: `idleLogout` empties the cart while this subscription is still
    // attached (React has not re-rendered yet). If the write were allowed, the
    // snapshot saved moments earlier would be overwritten with an empty cart and
    // the cashier would resume into nothing.
    renderHook(() => useIdleSession());
    act(() => {
      useCartStore.getState().addProduct(makeProduct(7, "Rice"));
      useCartStore.getState().addProduct(makeProduct(8, "Oil"));
    });

    await act(async () => {
      await useAuthStore.getState().idleLogout("/pos");
    });

    const saved = readWorkState(2, 9);
    expect(saved).not.toBeNull();
    expect(saved?.items).toHaveLength(2);
    expect(saved?.route).toBe("/pos");
    // Everything local to the session is gone and the reason is surfaced.
    expect(useCartStore.getState().items).toHaveLength(0);
    expect(getToken()).toBeNull();
    expect(useAuthStore.getState().status).toBe("unauthenticated");
    expect(useAuthStore.getState().error).toContain("inactivity");
  });

  it("never lets cart writes after sign-out touch the saved record", async () => {
    renderHook(() => useIdleSession());
    act(() => {
      useCartStore.getState().addProduct(makeProduct(7, "Rice"));
      useCartStore.getState().addProduct(makeProduct(8, "Oil"));
    });

    await act(async () => {
      await useAuthStore.getState().idleLogout("/pos");
      // Synchronous post-sign-out mutation: the subscription is still attached
      // (no re-render has happened inside this act), so only the principal guard
      // can stop this from rewriting the record.
      useCartStore.getState().addProduct(makeProduct(9, "Salt"));
    });

    expect(savedProductIds()).toEqual([7, 8]);
    expect(readWorkState(2, 9)?.route).toBe("/pos");
  });

  it("explicit logout discards the work state even with the subscription attached", async () => {
    renderHook(() => useIdleSession());
    act(() => {
      useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    });

    await act(async () => {
      await useAuthStore.getState().logout();
    });

    // Unlike an idle logout, a deliberate sign-out must leave nothing behind.
    expect(readWorkState(2, 9)).toBeNull();
    expect(useCartStore.getState().items).toHaveLength(0);
    expect(useAuthStore.getState().status).toBe("unauthenticated");
    expect(useAuthStore.getState().error).toBeNull();
  });

  it("stops persisting once the hook unmounts", () => {
    const { unmount } = renderHook(() => useIdleSession());
    unmount();

    act(() => {
      useCartStore.getState().addProduct(makeProduct(7, "Rice"));
    });

    expect(readWorkState(2, 9)).toBeNull();
  });
});
