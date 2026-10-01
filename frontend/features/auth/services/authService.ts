import { apiGet, apiPost } from "@/lib/api/request";
import type {
  AuthUser,
  LoginCredentials,
  LoginResponsePayload,
  MeResponsePayload,
} from "@/types/auth";

/**
 * Converts a `UserResource` payload into the frontend principal.
 *
 * `roles` and `capabilities` are only present when the API returned them, which
 * both auth endpoints do; a missing key is treated as "none" rather than
 * crashing a login. `capabilities` is what the permission gate reads, so it is
 * deliberately never defaulted to "everything" — an absent list must mean "no
 * access", otherwise a malformed response would silently unlock every module.
 */
function toAuthUser(payload: MeResponsePayload): AuthUser {
  return {
    id: payload.id,
    businessId: payload.business_id,
    name: payload.name,
    email: payload.email,
    createdAt: payload.created_at,
    roles: Array.isArray(payload.roles) ? payload.roles : [],
    capabilities: Array.isArray(payload.capabilities) ? payload.capabilities : [],
  };
}

/** A successful login: the principal plus the token to persist. */
export interface LoginOutcome {
  user: AuthUser;
  /** Sanctum personal access token; stored by the auth store, never in a component. */
  token: string;
}

/**
 * `POST /auth/login` — exchanges credentials for a Sanctum token.
 *
 * The only endpoint that is deliberately *not* bearer-authenticated, so the
 * shared client sends it without an `Authorization` header when no session
 * exists. The token is returned rather than stored here: persistence belongs
 * to the auth store, which keeps this service free of browser concerns.
 */
export async function login(credentials: LoginCredentials): Promise<LoginOutcome> {
  const payload = await apiPost<LoginResponsePayload>("/auth/login", {
    email: credentials.email,
    password: credentials.password,
  });

  return { user: toAuthUser(payload.user), token: payload.token };
}

/**
 * `GET /auth/me` — the current user behind the stored token.
 *
 * Used to restore a session after a reload. A `401` here means the token is
 * gone or revoked; the Axios interceptor has already cleared storage by the
 * time this rejects, so the caller only has to treat it as "signed out".
 */
export async function me(signal?: AbortSignal): Promise<AuthUser> {
  const payload = await apiGet<MeResponsePayload>("/auth/me", { signal });
  return toAuthUser(payload);
}

/**
 * `POST /auth/logout` — revokes the token used for the request.
 *
 * Rejects on failure. The auth store ignores that on purpose: the local token
 * is dropped either way, so a network failure must not trap the cashier in a
 * session the backend has already forgotten.
 */
export async function logout(): Promise<void> {
  await apiPost<null>("/auth/logout");
}