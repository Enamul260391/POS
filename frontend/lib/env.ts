/**
 * Centralised environment configuration.
 *
 * Every environment variable used by the application is read here so there is
 * exactly one place to update when the deployment environment changes.
 *
 * Only `NEXT_PUBLIC_*` variables are handled: they are inlined at build time
 * and are therefore safe to read from both server and client code. Sensitive
 * backend concerns (database credentials, API keys) live in Laravel, never here.
 */

/** Used when `NEXT_PUBLIC_API_URL` is missing (local Laravel development). */
const DEFAULT_API_URL = "http://localhost:8000/api/v1";

/** Used when `NEXT_PUBLIC_APP_NAME` is missing. */
const DEFAULT_APP_NAME = "POS";

/** Used when `NEXT_PUBLIC_API_TIMEOUT` is missing or invalid (milliseconds). */
const DEFAULT_API_TIMEOUT = 30_000;

/** Reads a public environment variable, treating blank values as unset. */
function readEnv(value: string | undefined): string | undefined {
  const trimmed = value?.trim();
  return trimmed ? trimmed : undefined;
}

/** Reads a positive integer environment variable, falling back when invalid. */
function readTimeOut(value: string | undefined): number {
  const parsed = Number(readEnv(value));
  return Number.isFinite(parsed) && parsed > 0 ? parsed : DEFAULT_API_TIMEOUT;
}

/** Reads a valid TCP port, falling back when absent or out of range. */
function readPort(value: string | undefined): number {
  const parsed = Number(readEnv(value));
  return Number.isInteger(parsed) && parsed > 0 && parsed <= 65535
    ? parsed
    : DEFAULT_REVERB_PORT;
}

/** Normalises a base URL by removing trailing slashes. */
function normalizeBaseUrl(value: string | undefined, fallback: string): string {
  return (readEnv(value) ?? fallback).replace(/\/+$/, "");
}

const apiUrl = normalizeBaseUrl(
  readEnv(process.env.NEXT_PUBLIC_API_BASE_URL) ?? process.env.NEXT_PUBLIC_API_URL,
  DEFAULT_API_URL,
);

/** `true` when the application runs from a production build. */
const isProduction = process.env.NODE_ENV === "production";

if (!isProduction && !readEnv(process.env.NEXT_PUBLIC_API_BASE_URL)) {
  console.warn(
    `[env] NEXT_PUBLIC_API_BASE_URL is not set. Falling back to "${DEFAULT_API_URL}". ` +
      "Create a .env.local file from .env.example to configure it.",
  );
}

// ---------------------------------------------------------------------------
// Realtime (Phase RT-1) — Laravel Reverb over WebSockets.
//
// These values must mirror the backend's REVERB_APP_KEY / REVERB_HOST /
// REVERB_PORT / REVERB_SCHEME. Only the **app key** is public: it identifies the
// Reverb application to the client, exactly as a Pusher key does. The app
// *secret* signs channel authorisations and never leaves the API — there is no
// `NEXT_PUBLIC_REVERB_APP_SECRET`, and adding one would put tenant data at risk.
//
// No production URL is hardcoded here; a deployment targets its own host purely
// through these variables.
// ---------------------------------------------------------------------------

/** Used when `NEXT_PUBLIC_REVERB_APP_KEY` is missing: realtime stays disabled. */
const DEFAULT_REVERB_HOST = "127.0.0.1";

/** Used when `NEXT_PUBLIC_REVERB_PORT` is missing or invalid. */
const DEFAULT_REVERB_PORT = 8080;

/**
 * `true` when the Reverb key is configured.
 *
 * Realtime is strictly optional. Without a key the app must keep working
 * exactly as it did before this phase: REST loads, CRUD and the POS all
 * function, just without live updates. `isRealtimeEnabled` gates every socket
 * code path on this.
 */
const reverbAppKey = readEnv(process.env.NEXT_PUBLIC_REVERB_APP_KEY);

/** Immutable, typed view of the public environment configuration. */
export const env = {
  /** Base URL of the Laravel REST API, without a trailing slash. */
  apiUrl,
  /** Application name shown in the UI and in the browser tab title. */
  appName: readEnv(process.env.NEXT_PUBLIC_APP_NAME) ?? DEFAULT_APP_NAME,
  /** Timeout applied to every HTTP request (milliseconds). */
  apiTimeout: readTimeOut(process.env.NEXT_PUBLIC_API_TIMEOUT),
  /** `true` when the application runs from a production build. */
  isProduction,
  /** `true` when a Reverb app key is configured and sockets may be opened. */
  isRealtimeEnabled: reverbAppKey !== undefined,
  /** Public Reverb app key, or `null` when realtime is not configured. */
  reverbAppKey: reverbAppKey ?? null,
  /** Reverb host the browser opens a WebSocket to. */
  reverbHost: readEnv(process.env.NEXT_PUBLIC_REVERB_HOST) ?? DEFAULT_REVERB_HOST,
  /** Reverb WebSocket port. */
  reverbPort: readPort(process.env.NEXT_PUBLIC_REVERB_PORT),
  /** `wss`/`https` in production, `ws`/`http` in local development. */
  reverbScheme: (readEnv(process.env.NEXT_PUBLIC_REVERB_SCHEME) ?? "http") as "http" | "https",
} as const;

/**
 * Absolute URL Echo authenticates private channels against.
 *
 * Derived from the API base URL rather than configured separately, so the two
 * can never drift: the signature must be produced by the same deployment that
 * owns the channel. `apiUrl` is `…/api/v1`, and the endpoint registered in
 * `bootstrap/app.php` is `api/v1/broadcasting/auth`.
 */
export const realtimeAuthEndpoint = `${env.apiUrl.replace(/\/api\/v\d+.*$/, "")}/api/v1/broadcasting/auth`;

