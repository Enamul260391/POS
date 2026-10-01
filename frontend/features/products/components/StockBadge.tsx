"use client";

import { Badge } from "@/components/ui/badge";
import { formatQuantity } from "@/features/pos/lib/format";
import { stockLevel } from "@/features/products/lib/products";
import type { Product } from "@/types/product";

interface StockBadgeProps {
  product: Product;
  className?: string;
}

/**
 * Stock indicator shared by the products and inventory tables.
 *
 * Wording and colours come from `stockLevel`, so both screens always agree —
 * the same rule the POS product cards follow.
 */
export function StockBadge({ product, className }: StockBadgeProps) {
  switch (stockLevel(product)) {
    case "untracked":
      return (
        <Badge variant="outline" className={className ?? "text-muted-foreground"}>
          Not tracked
        </Badge>
      );
    case "out":
      return <Badge variant="destructive" className={className}>Out of stock</Badge>;
    case "low":
      return (
        <Badge
          variant="outline"
          className={className ?? "border-warning/50 bg-warning/10 text-warning"}
        >
          Low: {formatQuantity(product.stock)}
        </Badge>
      );
    case "in":
      return (
        <Badge variant="secondary" className={className}>
          {formatQuantity(product.stock)}
        </Badge>
      );
  }
}
