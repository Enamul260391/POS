import axios, { isAxiosError, type AxiosError, type AxiosInstance } from "axios";

import { clearSession, getToken } from "@/lib/auth/token";
import { env } from "@/lib/env";
import type { ApiError, ApiValidationErrors } from "@/types/api";

/** Shown when Laravel returns an error without a usable `message` field. */
const DEFAULT_ERROR_MESSAGE = "Something went wrong. Please try again.";

/** Shown when the request exceeded `NEXT_PUBLIC_API_TIMEOUT`. */
const TIMEOUT_ERROR_MESSAGE = "The request timed out. Please try again.";

/** Shown when the API could not be reached (offline, CORS, server down). */
const NETWORK_ERROR_MESSAGE = "Unable to reach the server. Please check your connection.";

/** Shown when the API answers `401` and the session has been cleared. */
const UNAUTHENTICATED_MESSAGE = "Your session has expired. Please sign in again.";

/**
 * Shown when the API answers `429` without a usable `message`.
 *
 * The backend authors its own lockout wording on `POST /auth/login` — it names
 * the wait in seconds, which this constant cannot know. This is only the
 * fallback for a `429` from some other endpoint that said nothing useful, so
 * it stays deliberately generic rather than guessing at a duration.
 */
const RATE_LIMIT_MESSAGE =
  "Too many attempts. Please wait a moment and try again.";

/**
 * Handlers invoked when the API answers `401`.
 *
 * The Axios instance must not import the auth store (the store imports the
 * services, which import this client), so the session-clearing side effects
 * are injected from the outside. `useAuthStore` registers one handler on
 * import; until then the client still clears storage and simply rejects.
 */
type UnauthorizedHandler = () => void;

const unauthorizedHandlers = new Set<UnauthorizedHandler>();

/**
 * Registers a `401` side-effect handler and returns its unsubscribe function.
 *
 * Registration happens exactly once, from the auth store, so the client has a
 * single writer for "the token is no longer valid".
 */
export function onUnauthorized(handler: UnauthorizedHandler): () => void {
  unauthorizedHandlers.add(handler);
  return () => {
    unauthorizedHandlers.delete(handler);
  };
}

/** Narrowing helper for `unknown` values read from a response body. */
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

/** Extracts the top level `message` from a Laravel error payload. */
function readPayloadMessage(payload: unknown): string | undefined {
  if (!isRecord(payload)) {
    return undefined;
  }

  const message = payload.message;
  return typeof message === "string" && message.trim() ? message.trim() : undefined;
}

/** Converts a Laravel `errors` bag into `Record<string, string[]>`. */
function readValidationErrors(payload: unknown): ApiValidationErrors {
  if (!isRecord(payload) || !isRecord(payload.errors)) {
    return {};
  }

  const validationErrors: ApiValidationErrors = {};

  for (const [field, messages] of Object.entries(payload.errors)) {
    validationErrors[field] = Array.isArray(messages)
      ? messages.map((message) => String(message))
      : [String(messages)];
  }

  return validationErrors;
}

/** Picks the most useful message for a failed request. */
function resolveErrorMessage(
  error: AxiosError,
  isTimeout: boolean,
  isNetworkError: boolean,
): string {
  if (isTimeout) {
    return TIMEOUT_ERROR_MESSAGE;
  }

  if (isNetworkError) {
    return NETWORK_ERROR_MESSAGE;
  }

  // A `429` may carry Laravel's bare "Too many requests" (from a limiter that
  // authored no message) or, on the login lockout, wording that already states
  // how long to wait. Prefer whatever the server actually said; only replace a
  // message that is missing or the canonical placeholder.
  if (error.response?.status === 429) {
    const message = readPayloadMessage(error.response?.data);
    return message === undefined || message === "Too many requests"
      ? RATE_LIMIT_MESSAGE
      : message;
  }

  return readPayloadMessage(error.response?.data) ?? error.message ?? DEFAULT_ERROR_MESSAGE;
}

/** Converts any thrown value into the shared `ApiError` contract. */
function toApiError(error: unknown): ApiError {
  if (!isAxiosError(error)) {
    return {
      status: null,
      message: error instanceof Error && error.message ? error.message : DEFAULT_ERROR_MESSAGE,
      validationErrors: {},
      isValidationError: false,
      isAuthError: false,
      isNetworkError: false,
      isTimeout: false,
      cause: error,
    };
  }

  const status = error.response?.status ?? null;
  const isTimeout = error.code === "ECONNABORTED" || error.code === "ETIMEDOUT";
  const isNetworkError = error.response === undefined && !isTimeout;

  return {
    status,
    message: resolveErrorMessage(error, isTimeout, isNetworkError),
    validationErrors: readValidationErrors(error.response?.data),
    isValidationError: status === 422,
    isAuthError: status === 401 || status === 403,
    isNetworkError,
    isTimeout,
    cause: error,
  };
}

/**
 * Shared Axios instance used for every Laravel REST API request.
 *
 * - `baseURL` comes from `NEXT_PUBLIC_API_BASE_URL` via `@/lib/env` and already
 *   includes the `/api/v1` version prefix, so services use relative paths.
 * - A global timeout protects the cashier UI from hanging requests.
 * - JSON headers are set once so individual calls stay free of boilerplate.
 * - Failed requests are rejected with a normalised `ApiError`.
 *
 * Authentication (Laravel Sanctum personal access tokens) is handled here and
 * nowhere else:
 *
 *   request   attaches `Authorization: Bearer <token>` from `@/lib/auth/token`
 *   response  converts every failure to `ApiError`; a `401` additionally clears
 *             the stored session and notifies the registered handler
 *
 * `403` is deliberately *not* treated as a session problem: it means the user
 * is authenticated but lacks the permission, which the UI surfaces as an
 * "unauthorized" message rather than a forced sign-out.
 */
export const apiClient: AxiosInstance = axios.create({
  baseURL: env.apiUrl,
  timeout: env.apiTimeout,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-Requested-With": "XMLHttpRequest",
  },
  // Sanctum API tokens travel in the `Authorization` header, not as cookies, so
  // credentials must stay off: sending them would push the backend into
  // cookie/SPA mode and its 419 CSRF failures.
  withCredentials: false,
});

apiClient.interceptors.request.use((config) => {
  const token = getToken();
  if (token !== null) {
    // Set per request rather than once on the instance, so a login or a
    // logout takes effect on the very next call.
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    const apiError = toApiError(error);

    if (apiError.status === 401) {
      // The token is missing, expired or revoked: drop it before anything else
      // so no later request can replay it, then let the auth store redirect.
      clearSession();
      for (const handler of unauthorizedHandlers) {
        handler();
      }
      return Promise.reject({
        ...apiError,
        message:
          apiError.message === DEFAULT_ERROR_MESSAGE
            ? UNAUTHENTICATED_MESSAGE
            : apiError.message,
      } satisfies ApiError);
    }

    return Promise.reject(apiError);
  },
);
