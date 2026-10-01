"use client";

import { useCallback, useMemo } from "react";

import {
  EMPTY_RECEIPT_SETTINGS,
  toReceiptSettings,
  type ReceiptBusinessSettings,
} from "@/features/receipt/lib/receiptSettings";
import { getSettings } from "@/features/settings/services/settingsService";
import { useApiResource } from "@/lib/api/useApiResource";

/** What a receipt needs to know about the loaded business settings. */
export interface UseReceiptSettingsResult {
  /**
   * The business block to print, or {@link EMPTY_RECEIPT_SETTINGS} while the
   * request is in flight and when it failed.
   */
  settings: ReceiptBusinessSettings;
  /** `true` until the first `GET /settings` has settled (successfully or not). */
  isLoading: boolean;
  /** Display-safe message of the failure, or `null`. Never blocks printing. */
  error: string | null;
}

/**
 * Loads the business settings the receipt prints, through the existing
 * Settings service.
 *
 * This is a thin adapter, not a second API client: `getSettings` (and the
 * `GET /api/v1/settings` call behind it) already exists in
 * `@/features/settings/services/settingsService`, and `useApiResource` is the
 * hook every other screen fetches with. No new endpoint, no new store, no
 * `localStorage` — the receipt reads the same rows the Settings screen writes.
 *
 * Failure is deliberately non-fatal. A `403`, a `500` or a dropped connection
 * leaves `settings` at {@link EMPTY_RECEIPT_SETTINGS} and reports `error`; the
 * sale is already persisted, so its invoice number, lines and totals must still
 * be printable. The caller decides whether to surface a small notice — it must
 * never substitute placeholder business data.
 *
 * One request per mounted receipt, not per line item: the whole business block
 * is a single `ReceiptSale` value, read once when the receipt view mounts.
 */
export function useReceiptSettings(): UseReceiptSettingsResult {
  const load = useCallback((signal: AbortSignal) => getSettings({ signal }), []);
  const { data, isLoading, error } = useApiResource(load, []);

  const settings = useMemo(
    () => (data === null ? EMPTY_RECEIPT_SETTINGS : toReceiptSettings(data)),
    [data],
  );

  return { settings, isLoading, error };
}