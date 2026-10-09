import { beforeEach, describe, expect, it } from "vitest";

import { canAccessPath, capabilityForPath } from "@/lib/permissions/capabilities";
import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

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

const cashier: AuthUser = {
  id: 2,
  businessId: 9,
  isPlatformOwner: false,
  name: "Cashier",
  email: "cashier@example.test",
  createdAt: "2026-01-01T00:00:00Z",
  roles: ["cashier"],
  capabilities: ["sales.create", "sales.view"],
};

function signIn(user: AuthUser | null) {
  useAuthStore.setState({ user, status: user === null ? "unauthenticated" : "authenticated" });
}

beforeEach(() => {
  useAuthStore.setState({ user: null, status: "unknown" });
});

describe("capabilityForPath", () => {
  it("guards platform routes on the platform capability", () => {
    expect(capabilityForPath("/platform")).toBe("platform.businesses.view");
    expect(capabilityForPath("/platform/dashboard")).toBe("platform.businesses.view");
  });

  it("guards business routes on tenant capabilities", () => {
    expect(capabilityForPath("/pos")).toBe("sales.create");
    expect(capabilityForPath("/dashboard")).toBe("reports.view");
  });
});

describe("canAccessPath", () => {
  it("lets a role-less Platform Owner open the Platform dashboard (scenario 4)", () => {
    // A Platform Owner has an empty tenant capability list by design; the
    // platform dashboard must resolve through identity, not business permissions.
    signIn(platformOwner);

    expect(canAccessPath("/platform/dashboard")).toBe(true);
    expect(canAccessPath("/platform/businesses")).toBe(true);
  });

  it("does not treat a Platform Owner as authorised for business modules", () => {
    // Keeping authorization separate: platform identity grants no tenant access.
    signIn(platformOwner);

    expect(canAccessPath("/pos")).toBe(false);
    expect(canAccessPath("/dashboard")).toBe(false);
  });

  it("keeps business module permissions working for a real business user (scenario 7)", () => {
    signIn(cashier);

    expect(canAccessPath("/pos")).toBe(true);
    // A module the cashier holds no capability for is refused.
    expect(canAccessPath("/roles")).toBe(false);
  });

  it("does not deny while the session is still resolving", () => {
    // While `user` is null the gate is deliberately permissive so the shell is
    // not flashed with "not permitted" before restoration completes.
    signIn(null);
    useAuthStore.setState({ status: "unknown" });

    expect(canAccessPath("/pos")).toBe(true);
  });
});
