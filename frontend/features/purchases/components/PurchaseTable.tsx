"use client";

import { memo } from "react";
import { Eye, PackageSearch, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { PaymentStatusBadge } from "@/features/purchases/components/PaymentStatusBadge";
import { formatMoney } from "@/features/pos/lib/format";
import type { Purchase } from "@/types/purchase";
import { cn } from "cn";

interface PurchaseTableProps {
  /** Already filtered list — the screen memoises it. */
  purchases: readonly Purchase[];
  onView: (purchase: Purchase) => void;
}

/**
 * Purchase list table with row level actions (Phase 9).
 *
 * Performance: rows are memoised on the purchase reference — a purchase is
 * immutable after creation, so only rows entering or leaving the visible set
 * re-render on a filter change. Rows with an outstanding due carry a
 * permanent tint so "what do we still owe" is scannable. Actions are
 * read-only for now: editing a received order needs the reversal flow that
 * arrives with the Laravel API.
 */
export const PurchaseTable = memo(function PurchaseTable({
  purchases,
  onView,
}: PurchaseTableProps) {
  if (purchases.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <PackageSearch className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No purchases found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search or payment status.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[1000px]">
        <TableHeader>
          <TableRow>
            <TableHead>Reference</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Supplier</TableHead>
            <TableHead className="text-right">Items</TableHead>
            <TableHead className="text-right">Total</TableHead>
            <TableHead className="text-right">Paid</TableHead>
            <TableHead className="text-right">Due</TableHead>
            <TableHead>Payment status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {purchases.map((purchase) => (
            <PurchaseRow key={purchase.id} purchase={purchase} onView={onView} />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const PurchaseRow = memo(function PurchaseRow({
  purchase,
  onView,
}: {
  purchase: Purchase;
  onView: (purchase: Purchase) => void;
}) {
  return (
    <TableRow className={cn(purchase.dueAmount > 0 && "bg-warning/10")}>
      <TableCell className="font-mono text-xs">{purchase.referenceNumber}</TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground" suppressHydrationWarning>
        {new Date(purchase.purchasedAt).toISOString().slice(0, 10)}
      </TableCell>
      <TableCell className="max-w-56">
        <span className="block truncate font-medium" title={purchase.supplierName}>
          {purchase.supplierName}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {purchase.items.length}
      </TableCell>
      <TableCell className="text-right font-medium tabular-nums">
        {formatMoney(purchase.grandTotal)}
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {formatMoney(purchase.paidAmount)}
      </TableCell>
      <TableCell
        className={cn(
          "text-right tabular-nums",
          purchase.dueAmount > 0 ? "font-medium text-destructive" : "text-muted-foreground",
        )}
      >
        {formatMoney(purchase.dueAmount)}
      </TableCell>
      <TableCell>
        <PaymentStatusBadge status={purchase.paymentStatus} />
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`View ${purchase.referenceNumber}`}
            onClick={() => onView(purchase)}
          >
            <Eye aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled
            aria-disabled="true"
            aria-label={`Edit ${purchase.referenceNumber} — coming soon`}
            title="Editing a received purchase is coming soon"
          >
            <Pencil aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});