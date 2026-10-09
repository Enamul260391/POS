import { describe, expect, it } from "vitest";

import {
  BUSINESS_LANDING_ROUTE,
  LOGIN_ROUTE,
  PLATFORM_LANDING_ROUTE,
  businessRedirectTarget,
  isPlatformPath,
  isSafeInternalPath,
  landingRouteFor,
  platformRedirectTarget,
} from "@/features/auth/lib/routing";

/**
 * The single source of truth for the account-type routing decision, shared by
 * the login redirect and the root entry redirect. These tests pin the rule that
 * keeps the Platform Owner and Business User shells separate.
 */
describe("auth routing helpers", () => {
  it("uses /platform as the canonical Platform Owner landing route", () => {
    expect(PLATFORM_LANDING_ROUTE).toBe("/platform");
  });

  it("uses /pos as the Business User landing route", () => {
    expect(BUSINESS_LANDING_ROUTE).toBe("/pos");
  });

  it("routes an authenticated Platform Owner to /platform and a business user to /pos", () => {
    // Scenario 1 & 6: account type alone decides the landing shell.
    expect(landingRouteFor(true)).toBe("/platform");
    expect(landingRouteFor(false)).toBe("/pos");
  });

  describe("isSafeInternalPath", () => {
    it("accepts a single-slash internal path", () => {
      expect(isSafeInternalPath("/pos")).toBe(true);
      expect(isSafeInternalPath("/platform/dashboard")).toBe(true);
    });

    it("rejects a protocol-relative //host and external/empty values", () => {
      expect(isSafeInternalPath("//evil.example")).toBe(false);
      expect(isSafeInternalPath("https://evil.example")).toBe(false);
      expect(isSafeInternalPath("")).toBe(false);
      expect(isSafeInternalPath("pos")).toBe(false);
    });
  });

  describe("isPlatformPath", () => {
    it("matches /platform and any route beneath it", () => {
      expect(isPlatformPath("/platform")).toBe(true);
      expect(isPlatformPath("/platform/dashboard")).toBe(true);
      expect(isPlatformPath("/platform/businesses/3/edit")).toBe(true);
    });

    it("does not match business routes", () => {
      expect(isPlatformPath("/pos")).toBe(false);
      expect(isPlatformPath("/platform-x")).toBe(false);
    });
  });

  describe("platformRedirectTarget", () => {
    it("honours a safe platform route (scenario 2: restored after login)", () => {
      expect(platformRedirectTarget("/platform/businesses")).toBe("/platform/businesses");
    });

    it("discards a business route supplied through next (scenario 5)", () => {
      // A Platform Owner must never be sent into the business UI via ?next=.
      expect(platformRedirectTarget("/pos")).toBeNull();
      expect(platformRedirectTarget("/dashboard")).toBeNull();
    });

    it("discards unsafe/absent next values", () => {
      expect(platformRedirectTarget("//evil.example")).toBeNull();
      expect(platformRedirectTarget(null)).toBeNull();
    });
  });

  describe("businessRedirectTarget", () => {
    it("honours a safe business route", () => {
      expect(businessRedirectTarget("/pos")).toBe("/pos");
      expect(businessRedirectTarget("/sales/12")).toBe("/sales/12");
    });

    it("discards a platform route so a business user never enters the platform shell", () => {
      expect(businessRedirectTarget("/platform")).toBeNull();
      expect(businessRedirectTarget("/platform/dashboard")).toBeNull();
    });

    it("discards unsafe/absent next values", () => {
      expect(businessRedirectTarget("//evil.example")).toBeNull();
      expect(businessRedirectTarget(null)).toBeNull();
    });
  });

  it("exposes /login as the unauthenticated destination", () => {
    expect(LOGIN_ROUTE).toBe("/login");
  });
});
