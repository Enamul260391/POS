import { apiGet, apiPatch } from "@/lib/api/request";
import type { ApiRequestConfig } from "@/lib/api/request";

import { toSettings } from "@/features/settings/lib/settingMapper";
import type { SettingPayload } from "@/types/setting";

/**
 * Settings service — the only module that talks to `/settings`.
 *
 * The backend stores settings as free-form `(business_id, key, value)` rows and
 * exposes exactly two endpoints, both addressed to "the caller's own business":
 *
 *   GET    /settings  → `settings.view`
 *   PUT    /settings  → `settings.update`
 *   PATCH  /settings  → `settings.update`
 *
 * Tenancy: no request carries a `business_id`, and there is no `{business}` path
 * segment to tamper with. `SettingsController` takes the owning business from
 * the bearer token (`$request->user()->business_id`), so the client cannot
 * address another tenant's configuration. A `business_id` key in the body is
 * rejected by the API itself as a reserved key — this service never sends one.
 *
 * Update semantics: `UpdateSettingsRequest` reads the body root as a flat
 * `{ key: value }` map, and `SettingsService::update()` upserts exactly those
 * keys inside one transaction, leaving unmentioned keys untouched. `PATCH` is
 * therefore used, so the screen submits only the fields the user actually
 * changed and cannot clobber a concurrent change to an untouched key.
 *
 * Both endpoints answer with the **full** settings collection afterwards (not
 * just the keys that were written), so a save doubles as a refresh and the
 * caller never has to re-read to learn the server's final state.
 */

/** `GET /settings` — every non-sensitive setting of the caller's business. */
export async function getSettings(config?: ApiRequestConfig): Promise<Record<string, string | null>> {
  const payload = await apiGet<SettingPayload[]>("/settings", {
    signal: config?.signal,
  });

  return toSettings(payload);
}

/**
 * `PATCH /settings` — upserts the supplied keys and returns the full collection.
 *
 * The body is the flat map `UpdateSettingsRequest` expects, with every value
 * already normalised to `string | null` so the API's `nullable|string` rule can
 * never be violated by a stray number or boolean from a form.
 */
export async function updateSettings(
  values: Record<string, string | null>,
): Promise<Record<string, string | null>> {
  const payload = await apiPatch<SettingPayload[]>("/settings", values);

  return toSettings(payload);
}
