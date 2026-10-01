"use client";

import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type { Category } from "@/types/category";

interface CategoryTabsProps {
  /** Active categories loaded from `GET /categories`. */
  categories: readonly Category[];
  /** Selected category id; `null` means "All". */
  value: number | null;
  /** Reports the selected category id (`null` = "All"). */
  onChange: (categoryId: number | null) => void;
}

/**
 * Category filter tabs above the product grid.
 *
 * Base UI Tabs provides the accessible tablist (roving focus, arrow keys,
 * `aria-selected`) while `PosTerminal` keeps ownership of the selected id —
 * filtering state never lives inside a presentational component. Values are
 * strings because Base UI's tab value is loosely typed; the conversion to a
 * numeric category id happens in one place, here.
 *
 * Phase 25: the list comes from the API-backed catalogue. The API's categories
 * are flat, so every tab is a direct filter with no descendant matching.
 */
export function CategoryTabs({ categories, value, onChange }: CategoryTabsProps) {
  return (
    <Tabs
      value={value === null ? "all" : String(value)}
      onValueChange={(nextValue: unknown) => {
        onChange(
          typeof nextValue === "string" && nextValue !== "all"
            ? Number(nextValue)
            : null,
        );
      }}
      className="w-full"
    >
      <TabsList variant="line" className="h-auto flex-wrap justify-start gap-1">
        <TabsTrigger value="all">All</TabsTrigger>
        {categories.map((category) => (
          <TabsTrigger key={category.id} value={String(category.id)}>
            {category.name}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  );
}
