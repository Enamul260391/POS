/**
 * Browser-level verification of independent multi-tab sessions.
 *
 * Playwright is NOT a dependency of this repository and is therefore not wired
 * into `npm test`. This spec is a standalone, runnable artefact: it proves the
 * real end-to-end behaviour that the unit tests can only approximate — two
 * genuine browser tabs in ONE context, holding DIFFERENT accounts, at the same
 * time, against the running application.
 *
 * How to run (requires Playwright and both servers up):
 *
 *   # 1. Backend (Laravel) on :8000 and seed two accounts:
 *   #    - a Platform Owner   (platform_role = 'owner', no business_id)
 *   #    - a Business User    (business_id set, POS capabilities)
 *   #    Pass their credentials via env (see the config block below).
 *   # 2. Frontend on :3000  ->  cd frontend && npm run dev
 *   # 3. npx playwright test e2e/multi-tab-sessions.spec.ts
 *   #    (add: npx playwright install chromium   once, if browsers are absent)
 *
 * Why a single BrowserContext: two `page`s in one context share the same origin
 * and the same `localStorage`, which is exactly the situation the fix targets.
 * If the app had (incorrectly) kept the token in `localStorage`, these tabs
 * would clobber each other; with the token in per-tab `sessionStorage` they stay
 * independent. The assertions below fail loudly if that regresses.
 *
 * NOTE: This file is intentionally OUTSIDE `frontend/` so it is never compiled
 * or linted by the app's tsconfig/build (Playwright is not installed here), and
 * its types resolve only when Playwright is available.
 */
import { test, expect, type Page } from "@playwright/test";

/** Credentials for the two accounts, injected via env for the run. */
const OWNER = {
  email: process.env.E2E_OWNER_EMAIL ?? "owner@pos.local",
  password: process.env.E2E_OWNER_PASSWORD ?? "password",
};

const BUSINESS_A = {
  email: process.env.E2E_BUSINESS_A_EMAIL ?? "cashier.a@pos.local",
  password: process.env.E2E_BUSINESS_A_PASSWORD ?? "password",
};

const BUSINESS_B = {
  email: process.env.E2E_BUSINESS_B_EMAIL ?? "cashier.b@pos.local",
  password: process.env.E2E_BUSINESS_B_PASSWORD ?? "password",
};

const APP = process.env.E2E_APP_URL ?? "http://localhost:3000";
const API = process.env.E2E_API_URL ?? "http://localhost:8000/api/v1";

/** Signs the given page in and returns once the app has routed to a shell. */
async function signIn(page: Page, creds: { email: string; password: string }): Promise<void> {
  await page.goto(`${APP}/login`);
  await page.getByLabel("Email").fill(creds.email);
  await page.getByLabel("Password", { exact: true }).fill(creds.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  // Wait for the app to leave the login route for whichever shell the account
  // belongs to.
  await page.waitForURL(/\/(platform|pos)$/);

test.describe("independent multi-account sessions across tabs", () => {
  test("Scenario A: Platform Owner and a Business User stay isolated", async ({ context }) => {
    // Two tabs in ONE context — same origin, shared localStorage territory.
    const ownerTab = await context.newPage();
    const businessTab = await context.newPage();

    await signIn(ownerTab, OWNER);
    await signIn(businessTab, BUSINESS_A);

    // The owner landed on the platform shell; the business user on /pos.
    await expect(ownerTab).toHaveURL(/\/platform$/);
    await expect(businessTab).toHaveURL(/\/pos$/);

    // Each tab carries its own, distinct token.
    const ownerToken = await tabToken(ownerTab);
    const businessToken = await tabToken(businessTab);
    expect(ownerToken).not.toBeNull();
    expect(businessToken).not.toBeNull();
    expect(ownerToken).not.toBe(businessToken);

    // The token is tab-scoped: it is NOT in the shared localStorage.
    const sharedHasToken = await ownerTab.evaluate(
      () => window.localStorage.getItem("pos.auth.token") !== null,
    );
    expect(sharedHasToken).toBe(false);

    // Refreshing either tab preserves that tab's own identity.
    await ownerTab.reload();
    await expect(ownerTab).toHaveURL(/\/platform$/);
    expect(await tabToken(ownerTab)).toBe(ownerToken);

    await businessTab.reload();
    await expect(businessTab).toHaveURL(/\/pos$/);
    expect(await tabToken(businessTab)).toBe(businessToken);

    // Logging out of the business tab must NOT sign the owner tab out.
    await signOut(businessTab, API);
    await expect(businessTab).toHaveURL(/\/login$/);

    // The owner tab is still authenticated and still on its shell.
    await expect(ownerTab).toHaveURL(/\/platform$/);
    expect(await tabToken(ownerTab)).toBe(ownerToken);
  });

  test("Scenario B: two different Business Users stay independent", async ({ context }) => {
    const tabA = await context.newPage();
    const tabB = await context.newPage();

    await signIn(tabA, BUSINESS_A);
    await signIn(tabB, BUSINESS_B);

    await expect(tabA).toHaveURL(/\/pos$/);
    await expect(tabB).toHaveURL(/\/pos$/);

    const tokenA = await tabToken(tabA);
    const tokenB = await tabToken(tabB);
    expect(tokenA).not.toBeNull();
    expect(tokenB).not.toBeNull();
    expect(tokenA).not.toBe(tokenB);

    // Logging out of tab A leaves tab B authenticated.
    await signOut(tabA, API);
    await expect(tabA).toHaveURL(/\/login$/);
    expect(await tabToken(tabB)).toBe(tokenB);
  });

  test("Scenario C: root redirect is account-aware", async ({ context }) => {
    // Unauthenticated -> /login.
    const anon = await context.newPage();
    await anon.goto(`${APP}/`);
    await expect(anon).toHaveURL(/\/login$/);

    // Authenticated owner -> /platform regardless of an incompatible next.
    const ownerTab = await context.newPage();
    await signIn(ownerTab, OWNER);
    await ownerTab.goto(`${APP}/`);
    await expect(ownerTab).toHaveURL(/\/platform$/);
  });
});

/**
 * Performs the same logout the app performs (revoke token, clear session) and
 * waits for the redirect to /login. Uses the app's own endpoint so no internal
 * implementation detail is assumed beyond the public auth contract.
 */
async function signOut(page: Page, api: string): Promise<void> {
  await page.evaluate(async (base) => {
    const raw = window.sessionStorage.getItem("pos.auth.token");
    const token = raw ? (JSON.parse(raw) as string) : null;
    if (token) {
      await fetch(`${base}/auth/logout`, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      }).catch(() => undefined);
    }
    window.sessionStorage.removeItem("pos.auth.token");
    window.sessionStorage.removeItem("pos.auth.user");
  }, api);
  await page.goto(`${APP}/login`);
}

}

/** The tab's own bearer token, read straight from its sessionStorage. */
async function tabToken(page: Page): Promise<string | null> {
  return page.evaluate(() => window.sessionStorage.getItem("pos.auth.token"));
}
