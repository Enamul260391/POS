"use client";

import { Search } from "lucide-react";

import { Input } from "@/components/ui/input";

interface SupplierSearchProps {
  query: string;
  onQueryChange: (query: string) => void;
  shownCount: number;
  totalCount: number;
  /** `true` while the API is answering the search; suppresses the count flicker. */
  isLoading?: boolean;
}

/**
 * Search box above the supplier table (Phase 25).
 *
 * State stays in `SuppliersScreen` (local UI state — no store involvement).
 * Phase 25: the term is sent to `GET /suppliers?search=`, and while that request
 * is in flight the count line reads "Searching…" so the previous result set is
 * never mistaken for the answer to the new query.
 */
export function SupplierSearch({
  query,
  onQueryChange,
  shownCount,
  totalCount,
  isLoading = false,
}: SupplierSearchProps) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <div className="relative min-w-0 flex-1">
        <Search
          className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          type="search"
          className="pl-8"
          placeholder="Search name, phone, email or address…"
          aria-label="Search suppliers"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              onQueryChange("");
            }
          }}
        />
      </div>

      <p className="shrink-0 text-xs text-muted-foreground sm:ml-auto" aria-live="polite">
        {isLoading ? "Searching…" : `${shownCount} of ${totalCount} suppliers`}
      </p>
    </div>
  );
}