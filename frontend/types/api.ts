/**
 * Transport-level types shared by every REST API call.
 *
 * Laravel stays the single source of truth for business rules; the frontend
 * only describes the response envelope it expects to receive.
 *
 * The backend (`App\Support\ApiResponse`) assembles every response itself, so
 * these types mirror that single implementation exactly:
 *
 *   success  { "success": true,  "message": "...", "data": ... }
 *   error    { "success": false, "message": "...", "errors": { ... } }
 *   list     { "success": true,  "message": "...", "data": { "items": [], "pagination": {} } }
 */

/** Field level validation messages, keyed by field name (Laravel `422`). */
export type ApiValidationErrors = Record<string, string[]>;

/**
 * The envelope every successful endpoint answers with.
 *
 * `data` is `null` for endpoints that only acknowledge an action (logout,
 * delete, …) and an object/array for everything else.
 */
export interface ApiSuccess<T> {
  success: true;
  message: string;
  data: T;
}

/** The envelope every failing endpoint answers with. */
export interface ApiFailure {
  success: false;
  message: string;
  errors: ApiValidationErrors;
}

/** Either half of the contract, for code that inspects a raw body. */
export type ApiResponse<T> = ApiSuccess<T> | ApiFailure;

/**
 * Pagination metadata of a list response.
 *
 * Mirrors `App\Support\ApiResponse::paginated()` — this API deliberately does
 * not use Laravel's `meta`/`links` wrappers, so the frontend never has to
 * guess which of the two shapes it received.
 */
export interface ApiPagination {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
}

/** `data` of a paginated list response. */
export interface PaginatedData<T> {
  items: T[];
  pagination: ApiPagination;
}

/**
 * Normalised error produced by the shared Axios instance (`@/lib/axios`).
 *
 * Raw Axios errors are converted into this shape so that UI code can react to
 * an API failure without depending on Axios internals.
 */
export interface ApiError {
  /** HTTP status code, or `null` when the request never reached the API. */
  status: number | null;
  /** Message that is safe to display to the user. */
  message: string;
  /** Field level validation messages, empty for non-validation failures. */
  validationErrors: ApiValidationErrors;
  /** `true` when the API answered with `422 Unprocessable Content`. */
  isValidationError: boolean;
  /** `true` when the API answered with `401` or `403`. */
  isAuthError: boolean;
  /** `true` when the API could not be reached at all. */
  isNetworkError: boolean;
  /** `true` when the request exceeded the configured timeout. */
  isTimeout: boolean;
  /** Original error, kept for logging and debugging. */
  cause: unknown;
}
