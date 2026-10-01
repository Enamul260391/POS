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
  /** Owning tenant. Display only — never sent back in a request. */
  businessId: number;
  name: string;
  email: string;
  createdAt: string;
  /** Role slugs granted to this user; empty when the relation was not loaded. */
  roles: string[];
  /**
   * Capabilities this user satisfies, already resolved by the API.
   *
   * These are capability **names** (`users.view`), not the raw permission slugs
   * held through the roles (`users.manage`). The API did that resolution, so the
   * UI never re-implements the alias rules and cannot disagree with the Gate
   * that enforces them — comparing against the granted list is all it takes.
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
    business_id: number;
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