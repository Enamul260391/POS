import { useAuthStore } from "@/stores/useAuthStore";
import type { AuthUser } from "@/types/auth";

/**
 * Capability names, exactly as `PermissionService::CAPABILITIES` declares them.
 *
 * The API resolves these server-side (see `UserResource`), so the list is not a
 * re-statement of the permission rules — it is only the handle the client uses to
 * ask about them. Adding a capability here never grants anything; it only tells
 * the UI which module a rule belongs to.
 */
export type Capability = string;

/**
 * The capability each area of the application requires to be opened.
 *
 * The list is derived from the `can:` middleware on each route in
 * `backend/routes/api.php`, so a screen is gated on exactly what its own first
 * request is gated on — the module can never be hidden behind a capability the
 * API does not actually enforce, nor opened behind one it does.
 *
 * The page that owns a route decides what it needs; the map below is the one
 * place that fact is recorded. A route absent from this map is left open, which
 * is the safe direction for a route that has no `can:` guard of its own.
 */
export const ROUTE_CAPABILITIES: Readonly<Record<string, Capability>> = {
  "/dashboard": "reports.view",
  "/pos": "sales.create",
  "/products": "products.view",
  "/inventory": "inventory.view",
  "/sales": "sales.view",
  "/purchases": "purchases.view",
  "/customers": "customers.view",
  "/suppliers": "suppliers.view",
  "/expenses": "expenses.view",
  "/cash-drawer": "cash_registers.view",
  "/reports": "reports.view",
  "/users": "users.view",
  "/roles": "roles.view",
  "/settings": "settings.view",

  // Platform Owner routes
  "/platform": "platform.businesses.view",
  "/platform/dashboard": "platform.businesses.view",
  "/platform/businesses": "platform.businesses.view",
};

/**
 * The capability guarding a pathname, or `null` when the route is unguarded.
 *
 * Prefix matching, so a detail screen is gated by its parent's rule
 * (`/products/42` by `products.view`). The longest matching prefix wins, which
 * keeps a nested route from inheriting an unrelated sibling's rule.
 */
export function capabilityForPath(pathname: string): Capability | null {
  const exact = ROUTE_CAPABILITIES[pathname];
  if (exact !== undefined) {
    return exact;
  }

  const match = Object.keys(ROUTE_CAPABILITIES)
    .filter((route) => pathname === route || pathname.startsWith(`${route}/`))
    .sort((a, b) => b.length - a.length)[0];

  return match === undefined ? null : ROUTE_CAPABILITIES[match];
}

/**
 * `true` for capabilities that resolve through platform identity rather than
 * the tenant permission model.
 *
 * The backend defines every `platform.*` ability as
 * `User::isPlatformOwner()` (see `AppServiceProvider::PLATFORM_ABILITIES`) and
 * deliberately never publishes them in the `capabilities` list — that list is
 * the tenant permission model, which the API pins to an empty set for the
 * Platform Owner. Checking `capabilities` for a `platform.*` name would
 * therefore deny the very principal the route exists for, so identity is the
 * only correct source here.
 */
function isPlatformCapability(capability: Capability): boolean {
  return capability.startsWith("platform.");
}

/**
 * Does this signed-in principal satisfy `capability`?
 *
 * Platform capabilities resolve through {@link AuthUser.isPlatformOwner};
 * everything else is set membership in the API-resolved `capabilities` list.
 */
function userSatisfiesCapability(user: AuthUser, capability: Capability): boolean {
  if (isPlatformCapability(capability)) {
    return user.isPlatformOwner;
  }

  return user.capabilities.includes(capability);
}

/**
 * `true` when the signed-in user holds `capability`.
 *
 * Reads the resolved list from the session, so it is a plain set membership
 * check with no request involved.
 */
export function hasCapability(capability: Capability | null): boolean {
  if (capability === null) {
    return true;
  }

  const user = useAuthStore.getState().user;

  if (user === null) {
    return false;
  }

  return userSatisfiesCapability(user, capability);
}

/**
 * `true` when the signed-in user may open `pathname`.
 *
 * An unguarded route is always open. A guarded one is refused only once the
 * principal is actually known: while the session is still being restored the
 * capabilities are empty, and denying on that would flash the "not permitted"
 * message at every user on every full page load.
 */
export function canAccessPath(pathname: string): boolean {
  const capability = capabilityForPath(pathname);
  if (capability === null) {
    return true;
  }

  const user = useAuthStore.getState().user;
  if (user === null) {
    return true;
  }

  return userSatisfiesCapability(user, capability);
}