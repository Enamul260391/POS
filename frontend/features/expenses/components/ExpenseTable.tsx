"use client";

import { memo } from "react";
import { Pencil, ReceiptText, Trash2 } from "lucide-react";

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
  expenseCategoryLabel,
  expenseLabel,
  expensePaymentLabel,
} from "@/features/expenses/lib/expenses";
import { formatMoney } from "@/features/pos/lib/format";
import type { Expense } from "@/types/expense";

interface ExpenseTableProps {
  expenses: readonly Expense[];
  onEdit: (expense: Expense) => void;
  onDelete: (expense: Expense) => void;
  /** `true` while the first page is still loading. */
  isLoading: boolean;
}

/**
 * Expense list table.
 *
 * Rows memoise on the expense id. The date column shows `expenseDate` — the
 * `YYYY-MM-DD` calendar day exactly as stored — because the column is a `date`
 * with no time component; formatting it as an instant is what would make the
 * day appear to shift. The `description` is the only free-text note the table
 * has, since the mock's separate `title` column has no column behind it.
 */
export const ExpenseTable = memo(function ExpenseTable({
  expenses,
  onEdit,
  onDelete,
  isLoading,
}: ExpenseTableProps) {
  if (expenses.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <ReceiptText
          className="size-8 text-muted-foreground"
          aria-hidden="true"
        />
        <p className="text-sm font-medium">No expenses found</p>
        <p className="text-xs text-muted-foreground">
          {isLoading
            ? "Loading expenses…"
            : "Try a different search or category, or record one."}
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[920px]">
        <TableHeader>
          <TableRow>
            <TableHead>Reference</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Description</TableHead>
            <TableHead>Category</TableHead>
            <TableHead>Payment</TableHead>
            <TableHead>Recorded by</TableHead>
            <TableHead className="text-right">Amount</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {expenses.map((expense) => (
            <ExpenseRow
              key={expense.id}
              expense={expense}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const ExpenseRow = memo(function ExpenseRow({
  expense,
  onEdit,
  onDelete,
}: {
  expense: Expense;
  onEdit: (expense: Expense) => void;
  onDelete: (expense: Expense) => void;
}) {
  const label = expenseLabel(expense);

  return (
    <TableRow>
      <TableCell className="font-mono text-xs">{label}</TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
        {expense.expenseDate || "—"}
      </TableCell>
      <TableCell className="max-w-64">
        {expense.description ? (
          <span
            className="block truncate text-xs"
            title={expense.description}
          >
            {expense.description}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">—</span>
        )}
      </TableCell>
      <TableCell className="whitespace-nowrap text-muted-foreground">
        {expenseCategoryLabel(expense)}
      </TableCell>
      <TableCell className="whitespace-nowrap text-muted-foreground">
        {expensePaymentLabel(expense)}
      </TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
        {expense.recordedBy ?? "—"}
      </TableCell>
      <TableCell className="text-right font-medium tabular-nums">
        {formatMoney(expense.amount)}
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${label}`}
            title="Edit this expense"
            onClick={() => onEdit(expense)}
          >
            <Pencil aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Delete ${label}`}
            title="Delete this expense"
            onClick={() => onDelete(expense)}
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});
