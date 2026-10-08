/**
 * The authenticated principal, exactly as `UserResource` serialises it.
 *
 * Deliberately *not* the `@/types/user` staff-directory record: that one is
 * the management-area model (branch, notes, status, …) and would leak fields
 * the API never returns. The API is the only place roles are exposed, as role
 * **slugs** (`['admin']`), so permission checks stay slug based.
 */
export interface AuthUser {
  id: number;
  /**
   * Owning tenant. `null` for the Platform Owner, who belongs to no business.
   * Display only — never sent back in a request.
   */
  businessId: number | null;
  /**
   * Platform identity marker (`UserResource.is_platform_owner`).
   *
   * The backend resolves `platform.*` abilities from this identity alone (the
   * Gates in `AppServiceProvider::PLATFORM_ABILITIES` check
   * `User::isPlatformOwner()`), and it deliberately never publishes them in
   * `capabilities` — that list is the *tenant* permission model. The client
   * must therefore resolve `platform.*` route requirements from this flag,
   * not from `capabilities`, to read the same identity rule as the server.
   */
  isPlatformOwner: boolean;
  name: string;
  email: string;
  createdAt: string;
  /** Role slugs granted to this user; empty when the relation was not loaded. */
  roles: string[];
  /**
   * Capabilities this user satisfies, already resolved by the API.
   *
   * These are capability **names** (`users.view`), not the raw permission slugs
   * held through the roles. The API did that resolution, so the UI never
   * re-implements the capability-to-permission mapping and cannot disagree with
   * the Gate that enforces it — comparing against the granted list is all it
   * takes. `platform.*` abilities never appear here (see
   * {@link AuthUser.isPlatformOwner}).
   *
   * Empty means the user may open nothing, which is a legitimate state (a role
   * with no grants yet) and is deliberately *not* treated as "unknown".
   */
  capabilities: string[];
}

/** Everything a login needs. */
export interface LoginCredentials {
  email: string;
  password: string;
}

/**
 * Raw `data` payload of `POST /auth/login`.
 *
 * Mirrors `AuthController::login()`: the token, its type and the serialised
 * user. Declared here (rather than inline) so the mapper and the service agree
 * on one shape.
 */
export interface LoginResponsePayload {
  token: string;
  token_type: string;
  user: {
    id: number;
    /** Owning tenant; `null` for the Platform Owner. */
    business_id: number | null;
    /** Platform identity marker; see {@link AuthUser.isPlatformOwner}. */
    is_platform_owner: boolean;
    name: string;
    email: string;
    created_at: string;
    roles: string[];
    /** Resolved capability names; see {@link AuthUser.capabilities}. */
    capabilities: string[];
  };
}

/** `data` of `GET /auth/me`; identical to the login user, so it is reused. */
export type MeResponsePayload = LoginResponsePayload["user"];