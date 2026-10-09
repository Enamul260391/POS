/**
 * Routing decisions for authentication, kept as pure functions so the login
 * redirect, the root entry redirect and their tests all read the same rule.
 *
 * The application has two separate, non-overlapping landing areas:
 *
 *   - the Business User dashboard, under `/`, whose entry is `/pos`; and
 *   - the Platform Owner application, under `/platform`, whose own entry
 *     redirects to `/platform/dashboard`.
 *
 * A principal is one or the other, never both: the Platform Owner has no
 * business and no tenant role, so it must never be sent to a tenant route, and
 * a business user must never be sent into the platform shell. `?next=` is
 * attacker-controllable, so only a genuine internal path is ever honoured, and
 * even then only when it belongs to the side of the application the principal
 * is actually allowed on — access control itself is still enforced downstream
 * by `AuthGate` + `PermissionGate` and, authoritatively, by the API.
 */

/** Where an unauthenticated visitor is sent. */
export const LOGIN_ROUTE = "/login";

/** Where a Business User lands. */
export const BUSINESS_LANDING_ROUTE = "/pos";

/**
 * Where the Platform Owner lands.
 *
 * This is the application's canonical Platform Owner entry: the same route
 * `LoginForm` already redirects an owner to after sign-in. The `/platform`
 * page itself forwards to `/platform/dashboard`.
 */
export const PLATFORM_LANDING_ROUTE = "/platform";

/**
 * Whether `value` is a safe, same-origin, absolute path we may redirect to.
 *
 * It must begin with exactly one `/`. A value beginning with `//` is a
 * protocol-relative URL (e.g. `//evil.example`) and is rejected, as is anything
 * that does not start with `/` at all (an external `http(s)://` link, an empty
 * string, or a malformed value). `?next=` is attacker-controllable, so only a
 * genuine internal path is ever honoured.
 */
export function isSafeInternalPath(value: string): boolean {
  return value.startsWith("/") && !value.startsWith("//");
}

/**
 * Whether a safe internal path points into the Platform Owner application
 * (`/platform` itself or a route beneath it).
 */
export function isPlatformPath(value: string): boolean {
  return (
    value === PLATFORM_LANDING_ROUTE ||
    value.startsWith(`${PLATFORM_LANDING_ROUTE}/`)
  );
}

/**
 * Where an authenticated principal lands, decided purely by account type.
 *
 * The Platform Owner resolves to `/platform` regardless of any business role it
 * does or does not hold; a Business User resolves to `/pos`. This never grants
 * a business capability — it only chooses which guarded shell to enter, where
 * `PermissionGate` still decides what may actually be opened.
 */
export function landingRouteFor(isPlatformOwner: boolean): string {
  return isPlatformOwner ? PLATFORM_LANDING_ROUTE : BUSINESS_LANDING_ROUTE;
}

/**
 * The safe redirect destination for a Platform Owner, or `null` when `next`
 * cannot be honoured.
 *
 * A Platform Owner is only ever sent to a genuine platform route; a business
 * destination (`/pos`, `/dashboard`, …) supplied through `next` is discarded in
 * favour of {@link PLATFORM_LANDING_ROUTE}.
 */
export function platformRedirectTarget(next: string | null): string | null {
  return next !== null && isSafeInternalPath(next) && isPlatformPath(next)
    ? next
    : null;
}

/**
 * The safe redirect destination for a Business User, or `null` when `next`
 * cannot be honoured.
 *
 * A business user keeps the existing `next` behaviour but is never routed into
 * the platform shell: a `/platform` destination is discarded in favour of
 * {@link BUSINESS_LANDING_ROUTE}, leaving access control to `AuthGate` +
 * `PermissionGate`.
 */
export function businessRedirectTarget(next: string | null): string | null {
  return next !== null && isSafeInternalPath(next) && !isPlatformPath(next)
    ? next
    : null;
}
