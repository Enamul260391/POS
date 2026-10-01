"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type { ExpenseReport } from "@/types/report";
import { cn } from "cn";

interface ExpenseSummaryCardProps {
  /** The `GET /reports/expenses` response, already mapped. */
  expenses: ExpenseReport;
}

/**
 * Report: expense summary — `GET /reports/expenses`.
 *
 * Totals and the category breakdown are the API's own: the endpoint already
 * groups by `expense_category_id` and names each bucket, so the client only
 * turns the decimal strings into numbers and reads the share of the API's own
 * total for the bar. An uncategorised expense is a state the backend allows and
 * labels itself ("Uncategorized"), so it is listed rather than dropped.
 *
 * The cash share is shown as "—": the report has no `payment_method` split, so
 * there is no figure to read and none is invented from another endpoint.
 */
export const ExpenseSummaryCard = memo(function ExpenseSummaryCard({
  expenses,
}: ExpenseSummaryCardProps) {
  return (
    <ReportCard
      title="Expense summary"
      description={
        expenses.count > 0
          ? `${expenses.count} expenses · ${formatMoney(expenses.total)} total`
          : "No expenses in this period"
      }
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
        <ReportMetric label="Expenses" value={expenses.count} />
        <ReportMetric label="Total" value={formatMoney(expenses.total)} />
        <ReportMetric label="Cash" value="—" hint="No payment-method split" />
      </div>

      {expenses.count > 0 && expenses.byCategory.length > 0 && (
        <div className="pt-1">
          <p className="mb-2 text-xs font-medium text-muted-foreground">
            By category
          </p>
          <div className="space-y-3">
            {expenses.byCategory.map((row, index) => (
              <div
                key={`${row.category}-${index}`}
                className="flex items-center gap-3 text-sm"
              >
                <span
                  className={cn(
                    "h-2.5 w-2.5 rounded-xs",
                    index === 0 && "bg-chart-1",
                    index === 1 && "bg-chart-2",
                    index === 2 && "bg-chart-3",
                    index === 3 && "bg-chart-4",
                    index === 4 && "bg-chart-5",
                  )}
                  aria-hidden="true"
                />
                <span className="min-w-0 flex-1">{row.label}</span>
                <span className="tabular-nums">{formatMoney(row.amount)}</span>
                <span className="w-10 text-right tabular-nums">
                  {row.percent}%
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </ReportCard>
  );
});

