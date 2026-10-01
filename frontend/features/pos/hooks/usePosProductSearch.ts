"use client";

import { useCallback, useEffect, useState } from "react";

import { searchSellableProducts } from "@/features/products/services/productService";
import { useApiResource } from "@/lib/api/useApiResource";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { Product } from "@/types/product";

/** Quiet period after the last keystroke before a search request is sent. */
const SEARCH_DEBOUNCE_MS = 300;

/** What the POS terminal needs in order to render a product result. */
export interface PosProductSearch {
  /** Server matches for the current query; `[]` before anything is typed. */
  products: Product[];
  /** `true` while a search request is in flight. */
  isSearching: boolean;
  /** Display-safe message of the last search failure, or `null`. */
  error: string | null;
  /**
   * The value the request was actually made with.
   *
   * This is the **debounced, trimmed** query, not the raw input: it is the term
   * the rows in `products` correspond to, so the screen can tell "still settling
   * after typing" apart from "this is the answer for what you typed".
   */
  term: string;
}

/**
 * Server-side product search for the POS terminal.
 *
 * The terminal never holds the product catalogue. It holds only the rows the
 * backend returned for the current query, which is what keeps opening `/pos`
 * free of any product request at all:
 *
 *   - empty query  → `enabled: false`, so `useApiResource` runs no loader. There
 *     is no "load everything" fallback behind it; an empty query means no
 *     products, not all products.
 *   - non-empty    → one `GET /products?search=…&status=active` request.
 *
 * Debounce: a single 300 ms timer (the same quiet period the Purchases screen
 * uses). There is exactly one, and it is a plain `useEffect` timer rather than
 * `useDeferredValue`, because `useDeferredValue` only reorders renders — it
 * cannot stop a request per keystroke from leaving the browser.
 *
 * Race conditions are handled by `useApiResource` itself: each run gets its own
 * `AbortController`, a newer run aborts the previous one, and a superseded run
 * is refused by a monotonic `runIdRef`. So "Jon" resolving after "Jonshen" can
 * never overwrite the newer result. Nothing is re-implemented here.
 */
export function usePosProductSearch(
  query: string,
  categoryId: number | null,
): PosProductSearch {
  const [term, setTerm] = useState("");

  // The debounced term. Runs on mount too (with `""`), which is harmless: an
  // empty term disables the loader, so the initial render issues no request.
  useEffect(() => {
    const handle = setTimeout(() => setTerm(query.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [query]);

  const load = useCallback(
    (signal: AbortSignal) => searchSellableProducts(term, { categoryId }, { signal }),
    [term, categoryId],
  );

  const { data, isLoading, error } = useApiResource(load, [term, categoryId], {
    enabled: term !== "",
  });

  // Keep the rows the terminal has resolved in the catalogue cache, so a cart
  // line built from a search hit can still read its product's stock (the cart
  // resolves master data through the store, as it always has). This is a cache
  // write of the rows already on screen — it issues no request and does not mark
  // the catalogue as loaded.
  useEffect(() => {
    if (data !== null && data.length > 0) {
      useCatalogueStore.getState().cacheProducts(data);
    }
  }, [data]);

  const isActive = term !== "";

  return {
    // An empty query is the empty result set. `useApiResource` keeps the last
    // successful `data` when the loader is disabled, so clearing the search must
    // drop it here — otherwise stale rows would stay on screen, and re-enabling
    // it would flash the previous query's products.
    products: isActive ? (data ?? []) : [],
    // `isLoading` is flipped in a microtask, so on the first render after a
    // query starts it is still false; `data === null` covers that gap and keeps
    // the "No products found" state from flashing before the request lands.
    isSearching: isActive && (isLoading || data === null),
    error: isActive ? error : null,
    term,
  };
}
