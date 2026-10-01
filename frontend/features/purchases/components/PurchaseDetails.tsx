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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { PaymentStatusBadge } from "@/features/purchases/components/PaymentStatusBadge";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { formatLocalDateTime } from "@/lib/datetime";
import type { Purchase } from "@/types/purchase";
import { cn } from "cn";

interface PurchaseDetailsProps {
  purchase: Purchase;
  onOpenChange: (open: boolean) => void;
}

/** Read-only `label … value` row of the totals list. */
function DetailRow({
  term,
  value,
  valueClassName,
}: {
  term: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1">
      <dt className="shrink-0 text-sm text-muted-foreground">{term}</dt>
      <dd className={cn("text-right text-sm font-medium tabular-nums", valueClassName)}>
        {value}
      </dd>
    </div>
  );
}

/**
 * Read-only purchase detail view — the "View" action (Phase 9).
 *
 * Everything the table omits is readable here: the received lines with their
 * agreed cost and discount, the full totals breakdown (subtotal → discount →
 * tax → total → paid → due), payment status, order note and timestamps.
 * Numbers come straight from the stored document — no recalculation — so the
 * details can never disagree with what was recorded. Mounted only while open.
 */
export function PurchaseDetails({ purchase, onOpenChange }: PurchaseDetailsProps) {
  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle className="font-mono">{purchase.referenceNumber}</DialogTitle>
          <DialogDescription>
            {purchase.supplierName} ·{" "}
            <span suppressHydrationWarning>
              {formatLocalDateTime(purchase.purchasedAt)}
            </span>
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-center justify-between gap-2">
          <PaymentStatusBadge status={purchase.paymentStatus} />
          {purchase.dueAmount > 0 && (
            <Badge variant="destructive">
              {formatMoney(purchase.dueAmount)} due
            </Badge>
          )}
        </div>

        <div>
          <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            Items received
          </h3>
          <div className="overflow-hidden rounded-lg border border-border">
            <Table className="min-w-[480px]">
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Cost</TableHead>
                  <TableHead className="text-right">Discount</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {purchase.items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="max-w-44">
                      <span className="block truncate" title={item.productName}>
                        {item.productName}
                      </span>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{item.sku}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {formatQuantity(item.quantity)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {formatMoney(item.unitCost)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {item.discount > 0 ? `−${formatMoney(item.discount)}` : "—"}
                    </TableCell>
                    <TableCell className="text-right font-medium tabular-nums">
                      {formatMoney(item.lineTotal)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>

        <div>
          <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            Totals
          </h3>
          <dl className="divide-y divide-border">
            <DetailRow term="Subtotal" value={formatMoney(purchase.subtotal)} />
            <DetailRow term="Discount" value={`−${formatMoney(purchase.discountTotal)}`} />
            <DetailRow term={`Tax (VAT ${purchase.taxRate}%)`} value={formatMoney(purchase.taxTotal)} />
            <DetailRow term="Grand total" value={formatMoney(purchase.grandTotal)} />
            <DetailRow term="Paid" value={formatMoney(purchase.paidAmount)} />
            <DetailRow
              term="Due"
              value={formatMoney(purchase.dueAmount)}
              valueClassName={purchase.dueAmount > 0 ? "text-destructive" : undefined}
            />
            <DetailRow term="Note" value={purchase.note ?? "—"} />
            <DetailRow
              term="Created"
              value={formatLocalDateTime(purchase.createdAt)}
            />
          </dl>
        </div>

        <div className="flex justify-end">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}