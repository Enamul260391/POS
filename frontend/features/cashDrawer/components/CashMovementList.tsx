"use client";

import { memo } from "react";
import { ArrowDownLeft, ArrowUpRight, ListMinus, Pencil, Trash2 } from "lucide-react";

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
  cashTransactionLabel,
  cashTransactionReference,
  isManualCashMovement,
} from "@/features/cashDrawer/lib/cashDrawer";
import { formatMoney } from "@/features/pos/lib/format";
import { formatLocalDateTime } from "@/lib/datetime";
import { hasCapability } from "@/lib/permissions/capabilities";
import type { CashRegisterTransaction } from "@/types/cashDrawer";
import { cn } from "cn";

interface CashMovementListProps {
  /** The register's transactions, as returned by the API (newest first). */
  transactions: readonly CashRegisterTransaction[];
  /** `true` while the first page is loading. */
  isLoading?: boolean;
  /** Open the amendment dialog for a manual cash in / cash out. */
  onEdit?: (transaction: CashRegisterTransaction) => void;
  /** Open the removal dialog for a manual cash in / cash out. */
  onDelete?: (transaction: CashRegisterTransaction) => void;
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
 *
 * ## Row actions
 *
 * The action column needs **two** independent conditions, and both matter:
 *
 *   canUpdate = hasCapability("cash_registers.transactions.update")
 *               AND isManualCashMovement(transaction)
 *   canDelete = hasCapability("cash_registers.transactions.delete")
 *               AND isManualCashMovement(transaction)
 *
 * The permission half is what an administrator granted; the classification half
 * is what the row actually is. A sale- or expense-generated movement fails the
 * second condition however much authority the user holds, so the buttons are
 * never rendered for it — a user who may edit and remove manual movements still
 * sees no Edit and no Delete on a cash sale or an expense.
 *
 * This is presentation, not enforcement. `CashRegisterService` re-checks the
 * same classification before it writes, so the security boundary is the API and
 * not this component.
 */
export const CashMovementList = memo(function CashMovementList({
  transactions,
  isLoading = false,
  onEdit,
  onDelete,
}: CashMovementListProps) {
  // Resolved once per render: both booleans are consulted on every row, and the
  // session snapshot only changes on login or an explicit refresh. The handlers
  // being present is what the parent uses to say the drawer supports editing at
  // all, so a screen that wires nothing up renders no action column.
  const canUpdate =
    onEdit !== undefined &&
    hasCapability("cash_registers.transactions.update");
  const canDelete =
    onDelete !== undefined &&
    hasCapability("cash_registers.transactions.delete");
  const showActions = canUpdate || canDelete;

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
      <Table className="min-w-160">
        <TableHeader>
          <TableRow>
            <TableHead>Time</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Note</TableHead>
            <TableHead>By</TableHead>
            <TableHead className="text-right">Amount</TableHead>
            {showActions && (
              <TableHead className="w-24 text-right">Actions</TableHead>
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {transactions.map((transaction) => {
            const reference = cashTransactionReference(
              transaction.referenceType,
              transaction.referenceId,
            );
            // The classification gate, evaluated per row: the `sale`, `expense`
            // and `opening` / `closing` rows are all ineligible whatever the
            // permission says.
            const manual = isManualCashMovement(transaction);

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
                {showActions && (
                  <TableCell className="text-right">
                    {manual && (canUpdate || canDelete) ? (
                      <span className="inline-flex justify-end gap-1">
                        {canUpdate && onEdit !== undefined && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => onEdit(transaction)}
                            aria-label={`Edit ${cashTransactionLabel(transaction.type)} of ${formatMoney(transaction.amount)}`}
                          >
                            <Pencil aria-hidden="true" />
                          </Button>
                        )}
                        {canDelete && onDelete !== undefined && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => onDelete(transaction)}
                            aria-label={`Delete ${cashTransactionLabel(transaction.type)} of ${formatMoney(transaction.amount)}`}
                          >
                            <Trash2 aria-hidden="true" />
                          </Button>
                        )}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>
                )}
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
