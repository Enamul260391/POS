"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import type { ApiError } from "@/types/api";

/**
 * Page-level data fetching for API-backed screens.
 *
 * The project uses plain React state plus services, so this hook is the one
 * place where "fetch, cancel, expose loading/error" is implemented instead of
 * being repeated in every screen. It deliberately adds no cache layer: results
 * are held by the component that asked for them, so a list can never go stale
 * behind the user's back.
 *
 * Race conditions: every run gets its own `AbortController`. A newer run
 * aborts the previous one, and an aborted response is discarded instead of
 * overwriting newer data — so a slow request for "coca" can never land on top
 * of a fast request for "coca cola".
 *
 * React Strict Mode mounts effects twice in development; the cleanup aborts the
 * first run, so that costs one cancelled request rather than a duplicated list.
 */

/** What a screen needs to render one API-backed collection or record. */
export interface ApiResource<T> {
  /** The loaded value, or `null` while the first load is in flight. */
  data: T | null;
  /** `true` while a request is in flight. */
  isLoading: boolean;
  /** `true` while a *reload* is in flight over data already on screen. */
  isRefreshing: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;
  /** `true` when the last failure was `403` (authenticated but not allowed). */
  isForbidden: boolean;
  /** Re-runs the loader, keeping the current data on screen meanwhile. */
  refresh: () => void;
}

/** Options for {@link useApiResource}. */
export interface UseApiResourceOptions {
  /**
   * When `false` the loader is not run at all.
   *
   * Used to hold a request back until its inputs are ready (a category filter
   * that needs an id, for example) instead of firing a request that would be
   * rejected.
   */
  enabled?: boolean;
}

/** Extracts a display-safe message from anything a service rejected with. */
function messageOf(error: unknown): string {
  if (
    typeof error === "object" &&
    error !== null &&
    "message" in error &&
    typeof (error as { message: unknown }).message === "string"
  ) {
    const message = (error as { message: string }).message;
    if (message.trim() !== "") {
      return message;
    }
  }
  return "Something went wrong. Please try again.";
}

/** `true` when the rejection is a cancellation rather than a real failure. */
function isAbort(error: unknown): boolean {
  if (typeof error !== "object" || error === null || !("cause" in error)) {
    return false;
  }
  const cause: unknown = (error as { cause: unknown }).cause;
  return cause instanceof Error && cause.name === "CanceledError";
}

/**
 * Loads one value from a service and exposes it with its loading/error state.
 *
 * `loader` must be stable (wrap it in `useCallback`, or define it outside the
 * component) so the effect does not re-run on every render — that is the usual
 * cause of duplicate requests.
 */
export function useApiResource<T>(
  loader: (signal: AbortSignal) => Promise<T>,
  deps: readonly unknown[],
  options?: UseApiResourceOptions,
): ApiResource<T> {
  const enabled = options?.enabled ?? true;

  const [data, setData] = useState<T | null>(null);
  const [isLoading, setIsLoading] = useState(enabled);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isForbidden, setIsForbidden] = useState(false);
  const [reloadToken, setReloadToken] = useState(0);

  // Guards against a stale run writing into state after a newer one started.
  const runIdRef = useRef(0);

  const refresh = useCallback(() => {
    setReloadToken((token) => token + 1);
  }, []);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const controller = new AbortController();
    runIdRef.current += 1;
    const runId = runIdRef.current;

    // Transition flags asynchronously before invoking loader
    queueMicrotask(() => {
      if (runId === runIdRef.current) {
        setIsLoading(true);
        setIsRefreshing(true);
        setError(null);
        setIsForbidden(false);
      }
    });

    loader(controller.signal)
      .then((result) => {
        // A run that was superseded must not touch state.
        if (runId !== runIdRef.current) {
          return;
        }
        setData(result);
        setIsLoading(false);
        setIsRefreshing(false);
      })
      .catch((cause: unknown) => {
        if (isAbort(cause) || runId !== runIdRef.current) {
          return;
        }
        setError(messageOf(cause));
        setIsForbidden(
          typeof cause === "object" &&
            cause !== null &&
            "status" in cause &&
            (cause as ApiError).status === 403,
        );
        setIsLoading(false);
        setIsRefreshing(false);
      });

    return () => {
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, reloadToken, ...deps]);

  return { data, isLoading, isRefreshing, error, isForbidden, refresh };
}