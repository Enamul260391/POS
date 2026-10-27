"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { formatQuantity } from "@/features/pos/lib/format";
import { StockBadge } from "@/features/products/components/StockBadge";
import { STATUS_OPTIONS, UNIT_OPTIONS } from "@/features/products/lib/productSchema";
import { categoryPath } from "@/features/products/lib/products";
import { formatLocalDateTime } from "@/lib/datetime";
import type { Category } from "@/types/category";
import type { Product } from "@/types/product";

interface ProductViewDialogProps {
  product: Product;
  /** Categories loaded from the API, used to resolve the category row. */
  categories: readonly Category[];
  onOpenChange: (open: boolean) => void;
}

/** Read-only `label … value` row of the detail list. */
function DetailRow({ term, value }: { term: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1.5">
      <dt className="shrink-0 text-sm text-muted-foreground">{term}</dt>
      <dd className="text-right text-sm font-medium break-words">{value}</dd>
    </div>
  );
}

/**
 * Read-only product detail view (the "View" action).
 *
 * Everything the tables truncate or omit — full barcode, thresholds, timestamps —
 * is readable here without entering edit mode.
 * Mounted only while open, like the form dialog.
 */
export function ProductViewDialog({
  product,
  categories,
  onOpenChange,
}: ProductViewDialogProps) {
  const unitLabel = UNIT_OPTIONS.find((option) => option.value === product.unit)?.label
    ?? product.unit;
  const statusLabel =
    STATUS_OPTIONS.find((option) => option.value === product.status)?.label
    ?? product.status;

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{product.name}</DialogTitle>
          <DialogDescription>
            Product details · <span className="font-mono">{product.sku}</span>
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-center justify-between gap-2">
          <StockBadge product={product} />
          <Badge
            variant="outline"
            className={
              product.status === "active"
                ? "border-success/40 bg-success/10 text-success"
                : "text-muted-foreground"
            }
          >
            {statusLabel}
          </Badge>
        </div>

        <dl className="divide-y divide-border">
          <DetailRow term="Barcode" value={product.barcode ?? "—"} />
          <DetailRow term="Category" value={categoryPath(categories, product.categoryId)} />
          <DetailRow term="Unit" value={unitLabel} />
          <DetailRow
            term="Stock"
            value={
              product.unit === "service"
                ? "Not tracked"
                : `${formatQuantity(product.stock)} ${unitLabel} · low at ≤ ${formatQuantity(product.lowStockThreshold)}`
            }
          />
          <DetailRow term="Created" value={formatLocalDateTime(product.createdAt)} />
          <DetailRow term="Updated" value={formatLocalDateTime(product.updatedAt)} />
        </dl>

        <div className="flex justify-end">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
