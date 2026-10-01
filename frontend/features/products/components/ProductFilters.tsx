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
  categoryOptions,
  STATUS_FILTER_OPTIONS,
} from "@/features/products/lib/products";
import type { Category } from "@/types/category";
import type { ProductStatus } from "@/types/product";

interface ProductFiltersProps {
  query: string;
  onQueryChange: (query: string) => void;
  /** Categories loaded from `GET /categories`; drives the category select. */
  categories: readonly Category[];
  /** Selected category; `null` means all categories. */
  categoryId: number | null;
  onCategoryChange: (categoryId: number | null) => void;
  status: "all" | ProductStatus;
  onStatusChange: (status: "all" | ProductStatus) => void;
  shownCount: number;
  totalCount: number;
  /** `true` during the first load, when the count would read as a false zero. */
  isLoading?: boolean;
}

/**
 * Search box plus category and status filters above the product table.
 *
 * State stays in `ProductsScreen` (local UI state — no store involvement) so
 * the table can memoise on a single derived array. Select triggers render
 * their own label text, which keeps the placeholder/selected wording in one
 * place instead of relying on the primitive's value rendering.
 *
 * Phase 25: the category options come from the loaded API data rather than
 * `mock/categories.json`, and the count line says "Loading…" during the first
 * load so an empty table is never mistaken for an empty catalogue.
 */
export function ProductFilters({
  query,
  onQueryChange,
  categories,
  categoryId,
  onCategoryChange,
  status,
  onStatusChange,
  shownCount,
  totalCount,
  isLoading = false,
}: ProductFiltersProps) {
  const options = categoryOptions(categories);
  const categoryLabel =
    categoryId === null
      ? "All categories"
      : (options.find((option) => option.value === categoryId)?.label ?? "All categories");
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
          placeholder="Search name, SKU or barcode…"
          aria-label="Search products"
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
        value={categoryId === null ? "all" : String(categoryId)}
        onValueChange={(value) =>
          onCategoryChange(value === null || value === "all" ? null : Number(value))
        }
      >
        <SelectTrigger aria-label="Filter by category" className="w-full sm:w-48">
          <span className={categoryId === null ? "text-muted-foreground" : undefined}>
            {categoryLabel}
          </span>
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All categories</SelectItem>
          {options.map((option) => (
            <SelectItem key={option.value} value={String(option.value)}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <Select
        value={status}
        onValueChange={(value) =>
          onStatusChange((value ?? "all") as "all" | ProductStatus)
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
        {isLoading ? "Loading…" : `${shownCount} of ${totalCount} products`}
      </p>
    </div>
  );
}
