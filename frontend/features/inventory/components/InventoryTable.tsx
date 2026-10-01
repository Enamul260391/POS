"use client";

import { memo } from "react";
import { PackageSearch } from "lucide-react";

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { formatQuantity } from "@/features/pos/lib/format";
import { UNIT_OPTIONS } from "@/features/products/lib/productSchema";
import { StockBadge } from "@/features/products/components/StockBadge";
import { categoryPath, stockLevel } from "@/features/products/lib/products";
import type { Category } from "@/types/category";
import type { Product } from "@/types/product";
import { cn } from "cn";

interface InventoryTableProps {
  /** Already filtered list — the screen memoises it per tab. */
  products: readonly Product[];
  /** Categories loaded from the API, used to resolve the category column. */
  categories: readonly Category[];
  /** Opens the stock adjustment dialog for one row. */
  onAdjust: (product: Product) => void;
  /** Copy for the empty box; each tab explains its own state. */
  emptyTitle?: string;
  emptyHint?: string;
}

/**
 * Stock table shared by the inventory tabs (Phase 7).
 *
 * Performance mirrors `ProductTable`: the table and every row are memoised on
 * the product reference, so an adjustment re-renders one row while its
 * neighbours — and the movement list below — stay untouched where possible.
 * Low and out rows carry a permanent tint (the same wording/colours come from
 * `stockLevel`/`StockBadge`, so products and inventory always agree).
 *
 * Only stock-tracked products reach this table; the screen filters services.
 */
export const InventoryTable = memo(function InventoryTable({
  products,
  categories,
  onAdjust,
  emptyTitle = "No products",
  emptyHint = "Nothing to show here.",
}: InventoryTableProps) {
  if (products.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <PackageSearch className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">{emptyTitle}</p>
        <p className="text-xs text-muted-foreground">{emptyHint}</p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[900px]">
        <TableHeader>
          <TableRow>
            <TableHead>Product name</TableHead>
            <TableHead>SKU</TableHead>
            <TableHead>Category</TableHead>
            <TableHead className="text-right">On hand</TableHead>
            <TableHead className="text-right">Low threshold</TableHead>
            <TableHead>Stock level</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {products.map((product) => (
            <InventoryRow
              key={product.id}
              product={product}
              categories={categories}
              onAdjust={onAdjust}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const InventoryRow = memo(function InventoryRow({
  product,
  categories,
  onAdjust,
}: {
  product: Product;
  categories: readonly Category[];
  onAdjust: (product: Product) => void;
}) {
  const level = stockLevel(product);
  const unitLabel =
    UNIT_OPTIONS.find((option) => option.value === product.unit)?.label
    ?? product.unit;

  return (
    <TableRow
      className={cn(
        level === "out" && "bg-destructive/5",
        level === "low" && "bg-warning/10",
      )}
    >
      <TableCell className="max-w-56">
        <span className="block truncate font-medium" title={product.name}>
          {product.name}
        </span>
      </TableCell>
      <TableCell className="font-mono text-xs">{product.sku ?? "—"}</TableCell>
      <TableCell className="max-w-44">
        <span
          className="block truncate"
          title={categoryPath(categories, product.categoryId)}
        >
          {categoryPath(categories, product.categoryId)}
        </span>
      </TableCell>
      <TableCell className="text-right font-medium tabular-nums">
        {formatQuantity(product.stock)}
        <span className="ml-1 text-xs font-normal text-muted-foreground">
          {unitLabel}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        ≤ {formatQuantity(product.lowStockThreshold)}
      </TableCell>
      <TableCell>
        <StockBadge product={product} />
      </TableCell>
      <TableCell className="text-right">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onAdjust(product)}
          aria-label={`Adjust stock for ${product.name}`}
        >
          Adjust
        </Button>
      </TableCell>
    </TableRow>
  );
});