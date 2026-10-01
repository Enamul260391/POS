"use client";

import { Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import { SALE_PAYMENT_STATUS_FILTER_OPTIONS } from "@/features/sales/lib/sales";
import type { SalePaymentStatus } from "@/types/sale";

interface SalesFiltersProps {
  query: string;
  onQueryChange: (query: string) => void;
  paymentStatus: "all" | SalePaymentStatus;
  onPaymentStatusChange: (status: "all" | SalePaymentStatus) => void;
  shownCount: number;
  totalCount: number;
}

/**
 * Search box plus payment status filter above the sales table (Phase 10).
 *
 * State stays in `SalesScreen` (local UI state — no store involvement) so the
 * table can memoise on a single derived array; the select trigger renders its
 * own label text, keeping the selected wording in one place. The search covers
 * the three things a cashier is asked about at the counter: the invoice
 * number, the customer and who served them.
 */
export function SalesFilters({
  query,
  onQueryChange,
  paymentStatus,
  onPaymentStatusChange,
  shownCount,
  totalCount,
}: SalesFiltersProps) {
  const statusLabel =
    SALE_PAYMENT_STATUS_FILTER_OPTIONS.find((option) => option.value === paymentStatus)?.label
    ?? "All payments";

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
          placeholder="Search invoice, customer or cashier…"
          aria-label="Search sales"
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
        value={paymentStatus}
        onValueChange={(value) =>
          onPaymentStatusChange((value ?? "all") as "all" | SalePaymentStatus)
        }
      >
        <SelectTrigger aria-label="Filter by payment status" className="w-full sm:w-44">
          <span className={paymentStatus === "all" ? "text-muted-foreground" : undefined}>
            {statusLabel}
          </span>
        </SelectTrigger>
        <SelectContent>
          {SALE_PAYMENT_STATUS_FILTER_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <p className="shrink-0 text-xs text-muted-foreground sm:ml-auto">
        {shownCount} of {totalCount} invoices
      </p>
    </div>
  );
}
