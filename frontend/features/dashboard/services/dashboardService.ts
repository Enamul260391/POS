import { toDashboardSummary } from "@/features/dashboard/lib/dashboardMapper";
import { apiGet } from "@/lib/api/request";
import type { ApiRequestConfig } from "@/lib/api/request";
import type { DashboardPayload, DashboardSummary } from "@/types/dashboard";

/**
 * Dashboard service — the only module that talks to `/reports/dashboard`.
 *
 * The Dashboard is a thin, honest view over **one** existing endpoint:
 * `GET /api/v1/reports/dashboard` (`ReportController@dashboard`). It does not
 * fan out to `/sales`, `/purchases`, `/expenses`, `/products` or any other
 * module endpoint, and it does not aggregate anything locally — a second
 * client-side aggregation layer would be able to disagree with the server
 * about the same figures, which is exactly what the `/reports` screen avoids
 * by asking the API for its own totals.
 *
 * Today-only semantics: the screen sends **no query parameters at all**.
 * `ReportService::dashboard()` ignores request filters and hard-codes today's
 * window in the *business* timezone, so a date range would be a no-op at best
 * and a false promise at worst. The Dashboard is therefore "Today at a glance"
 * and does not reuse the `/reports` screen's date-range filter.
 *
 * Tenancy: the request carries no `business_id`/`user_id` and the URL has no
 * tenant segment. `ReportController@dashboard` takes the business from the
 * bearer token (`$request->user()->business_id`) and scopes every query to it,
 * so the client cannot address another tenant's totals.
 *
 * The service is a plain async function — no store, no cache, no polling — so
 * the screen's `useApiResource` is the single owner of loading and cancellation.
 */

/**
 * `GET /reports/dashboard` — the todays figures, mapped.
 *
 * @param config  Pass `signal` so a superseded or unmounted run is cancelled.
 */
export async function getDashboard(
  config?: ApiRequestConfig,
): Promise<DashboardSummary> {
  const payload = await apiGet<DashboardPayload>("/reports/dashboard", {
    signal: config?.signal,
  });

  return toDashboardSummary(payload);
}
