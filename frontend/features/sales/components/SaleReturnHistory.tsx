"use client";

import { Undo2 } from "lucide-react";

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import {
  RETURN_REASON_LABELS,
  returnUnitCount,
  saleReturnRefundLabel,
} from "@/features/sales/lib/returns";
import { formatLocalDateTime } from "@/lib/datetime";
import type { SaleReturn } from "@/types/saleReturn";

interface SaleReturnHistoryProps {
  /** Returns recorded against one invoice, newest first. */
  saleReturns: readonly SaleReturn[];
}

/**
 * Returns recorded against one invoice (Phase 10).
 *
 * Read from the stored return documents — nothing is recalculated — so the
 * invoice screen shows the same money that left the till, with the reason and
 * whether the goods went back on the shelf.
 */
export function SaleReturnHistory({ saleReturns }: SaleReturnHistoryProps) {
  if (saleReturns.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-8 text-center">
        <Undo2 className="size-6 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No returns on this invoice</p>
        <p className="text-xs text-muted-foreground">
          Items the customer brings back will be listed here.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <Table className="min-w-[720px]">
        <TableHeader>
          <TableRow>
            <TableHead>Return</TableHead>
            <TableHead>Date</TableHead>
            <TableHead className="text-right">Items</TableHead>
            <TableHead>Returned items</TableHead>
            <TableHead>Refund method</TableHead>
            <TableHead className="text-right">Refund</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {saleReturns.map((saleReturn) => (
            <TableRow key={saleReturn.id}>
              <TableCell className="align-top font-mono text-xs">
                {saleReturn.reference}
              </TableCell>
              <TableCell
                className="whitespace-nowrap align-top text-xs text-muted-foreground"
                suppressHydrationWarning
              >
                {formatLocalDateTime(saleReturn.returnedAt)}
              </TableCell>
              <TableCell className="align-top text-right tabular-nums text-muted-foreground">
                {formatQuantity(returnUnitCount(saleReturn))}
              </TableCell>
              <TableCell className="align-top text-xs text-muted-foreground">
                {saleReturn.items.map((item) => (
                  <span key={item.id} className="block">
                    {formatQuantity(item.quantity)} × {item.productName} —{" "}
                    {RETURN_REASON_LABELS[item.reason]}
                    {item.restocked ? " · restocked" : " · not restocked"}
                  </span>
                ))}
              </TableCell>
              <TableCell className="align-top whitespace-nowrap text-muted-foreground">
                {saleReturnRefundLabel(saleReturn)}
              </TableCell>
              <TableCell className="align-top text-right font-medium tabular-nums">
                {formatMoney(saleReturn.refundTotal)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
