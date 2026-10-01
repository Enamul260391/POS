import type { AxiosInstance, AxiosResponse } from "axios";

import { apiClient } from "@/lib/axios";
import type { ApiSuccess, PaginatedData } from "@/types/api";

/**
 * Thin, typed wrapper around the shared Axios instance.
 *
 * The backend wraps every payload in `{ success, message, data }`, so every
 * service would otherwise repeat "await the request, then reach into
 * `response.data.data`". This module owns that unwrapping once, which also
 * means there is exactly one place that has to change if the envelope ever
 * moves.
 *
 * It deliberately adds no cache, no retry and no state management: services
 * stay plain async functions, and callers decide what to do with the result.
 */

/** Options accepted by every helper; Axios options plus an abort signal. */
export interface ApiRequestConfig {
  /** Cancels the request when the caller no longer needs the answer. */
  signal?: AbortSignal;
  /** Query string values; `null`/`undefined`/`""` entries are dropped. */
  params?: Record<string, string | number | boolean | null | undefined>;
}

/**
 * Drops empty query values so an unset filter is simply not sent.
 *
 * Laravel's list requests treat `?search=` as "not provided" anyway, but not
 * sending it keeps the URL — and any cache keyed on it — stable.
 */
function cleanParams(
  params: ApiRequestConfig["params"],
): Record<string, string | number | boolean> | undefined {
  if (params === undefined) {
    return undefined;
  }

  const cleaned: Record<string, string | number | boolean> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === "") {
      continue;
    }
    cleaned[key] = value;
  }
  return Object.keys(cleaned).length > 0 ? cleaned : undefined;
}

/** Performs a request and returns the whole Axios response. */
export async function apiRequest<T>(
  method: "get" | "post" | "put" | "patch" | "delete",
  url: string,
  payload?: unknown,
  config?: ApiRequestConfig,
): Promise<AxiosResponse<ApiSuccess<T>>> {
  return apiClient.request<ApiSuccess<T>>({
    method,
    url,
    data: payload,
    params: cleanParams(config?.params),
    signal: config?.signal,
  });
}

/**
 * Performs a request and returns the envelope's `data`.
 *
 * This is what almost every service wants. Failures already reject with the
 * normalised `ApiError` from `@/lib/axios`, so callers only need a
 * `try`/`catch` when they want to show the message.
 */
export async function apiGet<T>(
  url: string,
  config?: ApiRequestConfig,
): Promise<T> {
  const response = await apiRequest<T>("get", url, undefined, config);
  return response.data.data;
}

/** `POST` returning the envelope's `data`. */
export async function apiPost<T>(
  url: string,
  payload?: unknown,
  config?: ApiRequestConfig,
): Promise<T> {
  const response = await apiRequest<T>("post", url, payload, config);
  return response.data.data;
}

/** `PUT` returning the envelope's `data`. */
export async function apiPut<T>(
  url: string,
  payload?: unknown,
  config?: ApiRequestConfig,
): Promise<T> {
  const response = await apiRequest<T>("put", url, payload, config);
  return response.data.data;
}

/** `PATCH` returning the envelope's `data`. */
export async function apiPatch<T>(
  url: string,
  payload?: unknown,
  config?: ApiRequestConfig,
): Promise<T> {
  const response = await apiRequest<T>("patch", url, payload, config);
  return response.data.data;
}

/** `DELETE` returning the envelope's `data` (usually `null`). */
export async function apiDelete<T>(
  url: string,
  config?: ApiRequestConfig,
): Promise<T> {
  const response = await apiRequest<T>("delete", url, undefined, config);
  return response.data.data;
}

/**
 * Performs a list request and returns the rows plus the pagination block.
 *
 * Every list endpoint in this API answers with `data.items` and
 * `data.pagination` (see `App\Support\ApiResponse::paginated()`), so screens
 * consume one consistent shape instead of branching on Laravel's two possible
 * pagination formats.
 */
export async function apiList<T>(
  url: string,
  config?: ApiRequestConfig,
): Promise<PaginatedData<T>> {
  const response = await apiRequest<PaginatedData<T>>("get", url, undefined, config);
  return response.data.data;
}
/**
 * The instance every helper goes through.
 *
 * Exported so a service can reach for a raw call (file downloads, for example)
 * without creating a second Axios client.
 */
export { apiClient };
export type { AxiosInstance, PaginatedData };