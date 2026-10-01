"use client";

import { Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import type { ExpenseCategory } from "@/types/expense";

interface ExpenseFiltersProps {
  query: string;
  onQueryChange: (query: string) => void;
  /** `null` disables the category filter. */
  categoryId: number | null;
  onCategoryChange: (categoryId: number | null) => void;
  categories: readonly ExpenseCategory[];
  shownCount: number;
  totalCount: number;
  isLoading: boolean;
}

/**
 * Search + category filter above the expense table.
 *
 * Both controls are server-side now: the values are handed straight to
 * `GET /expenses` as `?search=` and `?expense_category_id=`, which is what the
 * backend filters on in SQL. The categories come from the API rather than a
 * hardcoded list, because they are rows in `expense_categories`.
 *
 * A request is in flight whenever the user is typing, so `isLoading` drives a
 * quiet "updating…" hint instead of the empty state — otherwise every
 * keystroke would flash "no expenses found".
 */
export function ExpenseFilters({
  query,
  onQueryChange,
  categoryId,
  onCategoryChange,
  categories,
  shownCount,
  totalCount,
  isLoading,
}: ExpenseFiltersProps) {
  const categoryLabel =
    categories.find((category) => category.id === categoryId)?.name ??
    "All categories";

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <div className="relative min-w-0 flex-1">
        <Search
          className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          type="search"
          className="pl-8"
          placeholder="Search reference or note…"
          aria-label="Search expenses"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") onQueryChange("");
          }}
        />
      </div>

      <Select
        value={categoryId === null ? "all" : String(categoryId)}
        onValueChange={(value) =>
          onCategoryChange(value === "all" ? null : Number(value))
        }
      >
        <SelectTrigger aria-label="Filter by category" className="w-full sm:w-44">
          <span className={categoryId === null ? "text-muted-foreground" : undefined}>
            {categoryLabel}
          </span>
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All categories</SelectItem>
          {categories.map((category) => (
            <SelectItem key={category.id} value={String(category.id)}>
              {category.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <p className="shrink-0 text-xs text-muted-foreground sm:ml-auto" aria-live="polite">
        {isLoading ? "Updating…" : `${shownCount} of ${totalCount} expenses`}
      </p>
    </div>
  );
}
