"use client";

import { memo } from "react";
import { ArrowDownLeft, ArrowUpRight, ListMinus } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  cashTransactionLabel,
  cashTransactionReference,
} from "@/features/cashDrawer/lib/cashDrawer";
import { formatMoney } from "@/features/pos/lib/format";
import { formatLocalDateTime } from "@/lib/datetime";
import type { CashRegisterTransaction } from "@/types/cashDrawer";
import { cn } from "cn";

interface CashMovementListProps {
  /** The register's transactions, as returned by the API (newest first). */
  transactions: readonly CashRegisterTransaction[];
  /** `true` while the first page is loading. */
  isLoading?: boolean;
}

/**
 * Transaction history of the open register, exactly as the API returned it.
 *
 * Rows arrive newest first from `GET /cash-registers/{id}/transactions` and are
 * rendered in that order. The `sale` and `expense` rows in this table were
 * written by the sales and expense flows themselves, so the drawer never has to
 * create them — it only reads them back.
 *
 * `direction` decides the sign and colour, because the API always stores a
 * positive `amount` and classifies inflow/outflow by type. The `opening` and
 * `closing` markers are shown as badges: the backend excludes them from the
 * balance sums, so a sign would misreport them.
 */
export const CashMovementList = memo(function CashMovementList({
  transactions,
  isLoading = false,
}: CashMovementListProps) {
  if (transactions.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-10 text-center">
        <ListMinus className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">
          {isLoading ? "Loading transactions…" : "No transactions yet"}
        </p>
        <p className="text-xs text-muted-foreground">
          Cash sales, expenses and manual cash in / cash out will appear here as
          the API records them.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[640px]">
        <TableHeader>
          <TableRow>
            <TableHead>Time</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Note</TableHead>
            <TableHead>By</TableHead>
            <TableHead className="text-right">Amount</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {transactions.map((transaction) => {
            const reference = cashTransactionReference(
              transaction.referenceType,
              transaction.referenceId,
            );

            return (
              <TableRow key={transaction.id}>
                <TableCell
                  className="whitespace-nowrap text-xs text-muted-foreground"
                  suppressHydrationWarning
                >
                  {formatLocalDateTime(transaction.createdAt)}
                </TableCell>
                <TableCell>
                  <span className="inline-flex items-center gap-1.5">
                    <MovementIcon transaction={transaction} />
                    {cashTransactionLabel(transaction.type)}
                  </span>
                </TableCell>
                <TableCell className="max-w-64">
                  <span
                    className="block truncate text-muted-foreground"
                    title={transaction.note ?? undefined}
                  >
                    {transaction.note ?? "—"}
                  </span>
                  {reference !== null && (
                    <span className="block truncate text-xs text-muted-foreground/80">
                      {reference}
                    </span>
                  )}
                </TableCell>
                <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                  {transaction.recordedBy ?? "—"}
                </TableCell>
                <TableCell
                  className={cn(
                    "text-right font-medium tabular-nums",
                    transaction.direction === "out" && "text-destructive",
                    transaction.direction === "in" && "text-success",
                    transaction.direction === "neutral" && "text-muted-foreground",
                  )}
                >
                  {transaction.direction === "in"
                    ? `+${formatMoney(transaction.amount)}`
                    : transaction.direction === "out"
                      ? `-${formatMoney(transaction.amount)}`
                      : formatMoney(transaction.amount)}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
});

function MovementIcon({
  transaction,
}: {
  transaction: CashRegisterTransaction;
}) {
  if (transaction.direction === "out") {
    return <ArrowUpRight className="size-3.5 text-destructive" aria-hidden="true" />;
  }
  if (transaction.direction === "in") {
    return <ArrowDownLeft className="size-3.5 text-success" aria-hidden="true" />;
  }
  return (
    <Badge variant="secondary">
      {transaction.type === "opening" ? "Open" : "Close"}
    </Badge>
  );
}
