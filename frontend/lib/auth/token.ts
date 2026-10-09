/**
 * Central access to the Sanctum API token.
 *
 * The backend authenticates with `Authorization: Bearer <token>` only
 * (`bootstrap/app.php` deliberately does not enable Sanctum's SPA cookie
 * middleware), so the token has to live somewhere the Axios request
 * interceptor can read it.
 *
 * It is kept in **`sessionStorage`, not `localStorage`**, and that is the
 * single most important decision in this module. `localStorage` is shared by
 * every tab of the same origin, so a token stored there is a *single* shared
 * credential: logging in as a different account in a second tab overwrites the
 * first tab's token, its in-flight requests start carrying the wrong bearer,
 * and a logout in either tab wipes the credential the other tab still relies
 * on. `sessionStorage` is scoped to the individual tab (browsing context) and,
 * crucially, survives a reload of that same tab — which is exactly what
 * "restore session" needs while still giving each tab its own independent
 * identity. Two tabs can therefore hold two different accounts (a Platform
 * Owner and a Business User, or two different Business Users) at the same time
 * without ever seeing each other's credentials.
 *
 * Nothing else in the application may call `sessionStorage` for the token: the
 * key, the JSON shape and the null-handling live here so there is exactly one
 * place to change if the storage strategy ever does.
 *
 * Server-component safety: every function is a no-op when `window` is absent,
 * so importing this module from a server component is safe.
 */

/** Storage key. Namespaced so it can never collide with another key. */
const STORAGE_KEY = "pos.auth.token";

/** The authenticated user snapshot cached next to the token. */
const USER_KEY = "pos.auth.user";

/** `true` when the tab-scoped storage API is reachable (never during SSR). */
function canUseStorage(): boolean {
  return typeof window !== "undefined" && typeof window.sessionStorage !== "undefined";
}

/** Reads and parses a stored JSON value; unreadable data is treated as absent. */
function readJson<T>(key: string): T | null {
  if (!canUseStorage()) {
    return null;
  }
  try {
    const raw = window.sessionStorage.getItem(key);
    return raw === null ? null : (JSON.parse(raw) as T);
  } catch {
    // A corrupted entry must never break the app: it is treated as absent and
    // the session is re-established from `/auth/me`.
    return null;
  }
}

/** Writes a JSON value, ignoring quota/privacy-mode failures. */
function writeJson(key: string, value: unknown): void {
  if (!canUseStorage()) {
    return;
  }
  try {
    window.sessionStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage unavailable (private mode, quota): the session simply does not
    // survive a reload, which is not worth failing the request for.
  }
}

/** Removes a key, ignoring failures. */
function removeKey(key: string): void {
  if (!canUseStorage()) {
    return;
  }
  try {
    window.sessionStorage.removeItem(key);
  } catch {
    // Nothing to do — see `writeJson`.
  }
}

/** The stored bearer token, or `null` when signed out. */
export function getToken(): string | null {
  const token = readJson<string>(STORAGE_KEY);
  return typeof token === "string" && token.trim() !== "" ? token : null;
}

/** Stores the bearer token; `null` or blank clears it. */
export function setToken(token: string | null): void {
  if (token === null || token.trim() === "") {
    removeKey(STORAGE_KEY);
    return;
  }
  writeJson(STORAGE_KEY, token);
}

/**
 * The cached user snapshot, or `null`.
 *
 * Only used to paint the shell before `/auth/me` answers; the API response is
 * always the authority, so a stale or tampered value can never grant access —
 * the Axios request interceptor is what actually authorises a request.
 */
export function getCachedUser<T>(): T | null {
  return readJson<T>(USER_KEY);
}

/** Caches the user snapshot so a reload paints the shell immediately. */
export function setCachedUser(user: unknown): void {
  if (user === null || user === undefined) {
    removeKey(USER_KEY);
    return;
  }
  writeJson(USER_KEY, user);
}

/**
 * Clears every trace of the session.
 *
 * Called on explicit logout and, centrally, whenever the API answers `401`, so
 * an expired or revoked token can never be replayed.
 */
export function clearSession(): void {
  removeKey(STORAGE_KEY);
  removeKey(USER_KEY);
}