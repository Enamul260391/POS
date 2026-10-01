"use client";

import { memo } from "react";
import { Eye, FileSearch, Loader2, Undo2 } from "lucide-react";
import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatMoney } from "@/features/pos/lib/format";
import { SalePaymentStatusBadge } from "@/features/sales/components/SalePaymentStatusBadge";
import {
  cashierName,
  saleCustomerName,
  saleLineCount,
  salePaymentMethodLabel,
} from "@/features/sales/lib/sales";
import { formatLocalDateTime } from "@/lib/datetime";
import type { Sale } from "@/types/sale";
import { cn } from "cn";

interface SalesTableProps {
  /** Current page of invoices, already filtered and ordered by the API. */
  sales: readonly Sale[];
  /** Money already refunded per sale id; absent when nothing came back. */
  refundedBySale: ReadonlyMap<number, number>;
  /** Sale ids that still have something returnable. */
  returnableSaleIds: ReadonlySet<number>;
  onReturn: (sale: Sale) => void;
  /** `true` while the first page is still in flight. */
  isLoading?: boolean;
}

/**
 * Sales history table with row level actions (Phase 10, Phase 25).
 *
 * Performance: rows are memoised on the invoice reference — an invoice is
 * immutable, so only rows whose derived props (refunded amount, returnable)
 * changed re-render when a return is recorded. Rows with an outstanding due
 * carry a permanent tint so unsettled khata sales stay scannable, and a
 * refunded invoice carries a badge so the list never hides money that already
 * went back out.
 */
export const SalesTable = memo(function SalesTable({
  sales,
  refundedBySale,
  returnableSaleIds,
  onReturn,
  isLoading = false,
}: SalesTableProps) {
  if (isLoading) {
    return (
      <div className="flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-6 py-12 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" aria-hidden="true" />
        Loading invoices…
      </div>
    );
  }

  if (sales.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <FileSearch className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No invoices found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search or payment status.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[1080px]">
        <TableHeader>
          <TableRow>
            <TableHead>Invoice</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Customer</TableHead>
            <TableHead>Cashier</TableHead>
            <TableHead className="text-right">Items</TableHead>
            <TableHead className="text-right">Total</TableHead>
            <TableHead>Payment method</TableHead>
            <TableHead>Payment status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {sales.map((sale) => (
            <SaleRow
              key={sale.id}
              sale={sale}
              refundedAmount={refundedBySale.get(sale.id) ?? 0}
              canReturn={returnableSaleIds.has(sale.id)}
              onReturn={onReturn}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});
const SaleRow = memo(function SaleRow({
  sale,
  refundedAmount,
  canReturn,
  onReturn,
}: {
  sale: Sale;
  refundedAmount: number;
  canReturn: boolean;
  onReturn: (sale: Sale) => void;
}) {
  return (
    <TableRow className={cn(sale.dueAmount > 0 && "bg-warning/10")}>
      <TableCell className="whitespace-nowrap">
        <span className="block font-mono text-xs">{sale.invoiceNumber}</span>
        {refundedAmount > 0 && (
          <Badge variant="secondary" className="mt-0.5">
            Returned {formatMoney(refundedAmount)}
          </Badge>
        )}
      </TableCell>
      <TableCell
        className="whitespace-nowrap text-xs text-muted-foreground"
        suppressHydrationWarning
      >
        {formatLocalDateTime(sale.soldAt)}
      </TableCell>
      <TableCell className="max-w-48">
        <span className="block truncate font-medium" title={saleCustomerName(sale)}>
          {saleCustomerName(sale)}
        </span>
      </TableCell>
      <TableCell className="max-w-40">
        <span
          className="block truncate text-muted-foreground"
          title={cashierName(sale)}
        >
          {cashierName(sale)}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {saleLineCount(sale)}
      </TableCell>
      <TableCell className="text-right font-medium tabular-nums">
        {formatMoney(sale.grandTotal)}
      </TableCell>
      <TableCell className="whitespace-nowrap text-muted-foreground">
        {salePaymentMethodLabel(sale)}
      </TableCell>
      <TableCell>
        <SalePaymentStatusBadge status={sale.paymentStatus} />
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Link
            href={`/sales/${sale.id}`}
            aria-label={`View invoice ${sale.invoiceNumber}`}
            title="Open the invoice"
            className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
          >
            <Eye aria-hidden="true" />
          </Link>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled={!canReturn}
            aria-disabled={!canReturn}
            aria-label={`Return items from ${sale.invoiceNumber}`}
            title={
              canReturn
                ? "Return items from this invoice"
                : "Nothing left to return on this invoice"
            }
            onClick={() => onReturn(sale)}
          >
            <Undo2 aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});
