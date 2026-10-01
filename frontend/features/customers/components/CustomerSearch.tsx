"use client";

import { Loader2, Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import {
  STATUS_FILTER_OPTIONS,
} from "@/features/customers/lib/customers";
import type { CustomerStatus } from "@/types/customer";

interface CustomerSearchProps {
  query: string;
  onQueryChange: (query: string) => void;
  status: "all" | CustomerStatus;
  onStatusChange: (status: "all" | CustomerStatus) => void;
  shownCount: number;
  /** `true` while a search is in flight. */
  isLoading?: boolean;
}

/**
 * Search box plus status filter above the customer table (Phase 8, Phase 25).
 *
 * Covers both the "Search" and "Filter" requirements in one row; state stays
 * in `CustomersScreen` (local UI state — no store involvement). The select
 * trigger renders its own label text, keeping the selected wording in one
 * place.
 *
 * Phase 25: the term is debounced and sent to the API rather than filtering a
 * downloaded directory, so the count line reports what the **server** matched.
 */
export function CustomerSearch({
  query,
  onQueryChange,
  status,
  onStatusChange,
  shownCount,
  isLoading = false,
}: CustomerSearchProps) {
  const statusLabel =
    STATUS_FILTER_OPTIONS.find((option) => option.value === status)?.label ?? "All statuses";

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
          aria-label="Search customers"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              onQueryChange("");
            }
          }}
        />
      </div>

      <Select
        value={status}
        onValueChange={(value) =>
          onStatusChange((value ?? "all") as "all" | CustomerStatus)
        }
      >
        <SelectTrigger aria-label="Filter by status" className="w-full sm:w-40">
          <span className={status === "all" ? "text-muted-foreground" : undefined}>
            {statusLabel}
          </span>
        </SelectTrigger>
        <SelectContent>
          {STATUS_FILTER_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <p className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground sm:ml-auto">
        {isLoading && <Loader2 className="size-3 animate-spin" aria-hidden="true" />}
        {isLoading ? "Searching…" : `${shownCount} customers`}
      </p>
    </div>
  );
}