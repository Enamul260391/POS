# Browser-level multi-tab verification (Playwright)

`multi-tab-sessions.spec.ts` proves — in a **real browser** — that separate tabs
of the same origin can hold **different authenticated accounts** at the same
time, refresh independently, and log out independently.

Playwright is intentionally **not** a dependency of this repository, so this
spec is not wired into `npm test` and is not part of the app's TypeScript
build. It is a standalone artefact you run when you want end-to-end proof.

## Prerequisites

1. Backend (Laravel) running on `http://localhost:8000`, with two seeded
   accounts:
   - a **Platform Owner** (`platform_role = 'owner'`, no `business_id`)
   - a **Business User** (`business_id` set, POS capabilities)
   - optionally a second Business User for Scenario B
2. Frontend running on `http://localhost:3000` (`cd frontend && npm run dev`).
3. Playwright + a browser:
   ```bash
   npm i -D @playwright/test
   npx playwright install chromium
   ```

## Run

```bash
# from the repository root
E2E_OWNER_EMAIL=owner@pos.local E2E_OWNER_PASSWORD=password \
E2E_BUSINESS_A_EMAIL=cashier.a@pos.local E2E_BUSINESS_A_PASSWORD=password \
E2E_BUSINESS_B_EMAIL=cashier.b@pos.local E2E_BUSINESS_B_PASSWORD=password \
npx playwright test e2e/multi-tab-sessions.spec.ts
```

## What it asserts

- **Scenario A** — Platform Owner (Tab 1 -> `/platform`) and a Business User
  (Tab 2 -> `/pos`) coexist; each tab holds a distinct token; the token is in
  per-tab `sessionStorage`, never the shared `localStorage`; refreshing either
  tab preserves that tab's identity; logging out of one tab does not sign the
  other out.
- **Scenario B** — two different Business Users remain independent; logging out
  of one leaves the other authenticated.
- **Scenario C** — an unauthenticated visitor is redirected to `/login`, and an
  authenticated Platform Owner opening `/` reaches `/platform`.

> **Status in this repository:** these tests were **not executed** here because
> Playwright is not installed and neither the Next.js nor the Laravel server was
> running. The behaviour they assert is covered at the unit/integration layer by
> `frontend/lib/auth/__tests__/token.test.ts`,
> `frontend/lib/__tests__/axios.test.ts`, and
> `frontend/stores/__tests__/useAuthStore.tabIsolation.test.ts`.
