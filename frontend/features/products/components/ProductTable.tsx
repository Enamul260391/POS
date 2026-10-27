"use client";

import { memo } from "react";
import { Eye, PackageSearch, Pencil, Trash2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  categoryPath,
  stockLevel,
} from "@/features/products/lib/products";
import { StockBadge } from "@/features/products/components/StockBadge";
import type { Category } from "@/types/category";
import type { Product } from "@/types/product";
import { cn } from "cn";

interface ProductTableProps {
  /** Already filtered list — the screen memoises it. */
  products: readonly Product[];
  /** Categories loaded from the API, used to resolve the category column. */
  categories: readonly Category[];
  onView: (product: Product) => void;
  onEdit: (product: Product) => void;
}

/**
 * Status pill of a product (independent from the stock level).
 *
 * Phase 25: `discontinued` is gone — `Product::STATUSES` only allows
 * `active` / `inactive`.
 */
function StatusBadge({ product }: { product: Product }) {
  if (product.status === "active") {
    return (
      <Badge variant="outline" className="border-success/40 bg-success/10 text-success">
        Active
      </Badge>
    );
  }
  return <Badge variant="secondary">Inactive</Badge>;
}

/**
 * Product master table with row level actions.
 *
 * Performance: rows are memoised on the product reference, so a filter change
 * re-renders only rows that entered or left the visible set, and an add/edit
 * re-renders one row (every other product keeps its object identity). Rows
 * with no or low stock carry a permanent tint so problems are scannable
 * without reading every badge.
 *
 * Delete is a visible but disabled placeholder: deleting master data needs
 * the confirmation flow and the API that comes with Laravel.
 */
export const ProductTable = memo(function ProductTable({
  products,
  categories,
  onView,
  onEdit,
}: ProductTableProps) {
  if (products.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <PackageSearch className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No products found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search, category or status.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[960px]">
        <TableHeader>
          <TableRow>
            <TableHead>Product name</TableHead>
            <TableHead>SKU</TableHead>
            <TableHead>Barcode</TableHead>
            <TableHead>Category</TableHead>
            <TableHead className="text-right">Stock</TableHead>
            <TableHead>Status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {products.map((product) => (
            <ProductRow
              key={product.id}
              product={product}
              categories={categories}
              onView={onView}
              onEdit={onEdit}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const ProductRow = memo(function ProductRow({
  product,
  categories,
  onView,
  onEdit,
}: {
  product: Product;
  categories: readonly Category[];
  onView: (product: Product) => void;
  onEdit: (product: Product) => void;
}) {
  const level = stockLevel(product);

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
      <TableCell className="font-mono text-xs text-muted-foreground">
        {product.barcode ?? "—"}
      </TableCell>
      <TableCell className="max-w-44">
        <span className="block truncate" title={categoryPath(categories, product.categoryId)}>
          {categoryPath(categories, product.categoryId)}
        </span>
      </TableCell>
      <TableCell>
        <div className="flex justify-end">
          <StockBadge product={product} />
        </div>
      </TableCell>
      <TableCell>
        <StatusBadge product={product} />
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`View ${product.name}`}
            onClick={() => onView(product)}
          >
            <Eye aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${product.name}`}
            onClick={() => onEdit(product)}
          >
            <Pencil aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled
            aria-disabled="true"
            aria-label={`Delete ${product.name} — coming soon`}
            title="Delete coming soon"
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});
