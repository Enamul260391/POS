"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type {
  CustomerOutstandingReport,
  CustomerOutstandingRow,
} from "@/types/report";

interface CustomerOutstandingCardProps {
  /** The `GET /reports/customer-outstanding` response, already mapped. */
  report: CustomerOutstandingReport;
}

/**
 * Report: **customer outstanding / receivables** —
 * `GET /reports/customer-outstanding`.
 *
 * A pure function of one mapped report, so it issues **no request of its own**:
 * the screen loads it alongside the other reports and hands the object down.
 *
 * ## These figures are the ledger's, not the invoices'
 *
 * Every balance is `SUM(customer_ledger_entries.amount)` for that customer over
 * the ledger's whole history. `sales.due_amount` is deliberately **not** what
 * this card shows and is never labelled as such: that column is revised by a
 * later payment but never by a later return, so summing it would report money the
 * business already holds. The card says "Outstanding" and "Customer credit"
 * precisely so nobody reads it as the invoice column.
 *
 * ## The date filter does not move these numbers
 *
 * A balance is a standing position, not a period total: a payment made last month
 * reduces the debt *today* whether or not it falls inside the window. So the
 * summary below is not described by the selected range — only the movement figure
 * is, and it is shown as its own labelled metric rather than folded into the
 * totals. The caption says so outright rather than leaving a user to wonder why
 * changing the month left the money unchanged.
 *
 * ## Positive and negative are shown, not merged
 *
 * The ledger's rule is **positive = the customer owes the business, negative = the
 * business owes the customer.** Both directions appear: receivable and credit are
 * separate summary figures with the net beside them, and every row carries the
 * side the API named it. Nothing is made absolute — a credit row still shows its
 * negative balance, because that is what the ledger says and the table must not
 * contradict it.
 *
 * ## No fabricated rows
 *
 * The listing is exactly what the API returned: only customers with a non-zero
 * balance (the settled ones are dropped in SQL), and only customers this business
 * actually owns. No walk-in placeholder is invented — the ledger's `customer_id`
 * is non-nullable, so a counter sale writes no row and there is nothing to
 * represent. An empty list is stated as an explicit empty state rather than padded
 * with a zero-valued customer that would look like data.
 */
export const CustomerOutstandingCard = memo(function CustomerOutstandingCard({
  report,
}: CustomerOutstandingCardProps) {
  const { customers } = report;

  return (
    <ReportCard
      title="Customer outstanding"
      description={describe(report)}
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
        <ReportMetric
          label="Total receivable"
          value={formatMoney(report.receivable)}
          hint="Owed to the business"
        />
        <ReportMetric
          label="Customer credit"
          value={formatMoney(report.customerCredit)}
          hint="The business owes"
        />
        <ReportMetric
          label="Net outstanding"
          value={formatMoney(report.netOutstanding)}
          hint={netHint(report.netOutstanding)}
        />
        <ReportMetric
          label="Period movement"
          value={formatMoney(report.periodMovement)}
          hint="In the selected period"
        />
      </div>

      {customers.length > 0 ? (
        <>
          <p className="text-xs text-muted-foreground">
            {report.customerCount} customer
            {report.customerCount === 1 ? "" : "s"} with a non-zero balance.
          </p>
          <OutstandingTable customers={customers} />
        </>
      ) : (
        <p className="text-xs text-muted-foreground">
          Every customer account is settled — nothing is outstanding and no credit
          is held.
        </p>
      )}
    </ReportCard>
  );
});

/**
 * The listing: customer, balance, status.
 *
 * Plain markup and the same grid language as the leaderboards beside it — one
 * figure per customer, no chart dependency to add. `tabular-nums` is what makes a
 * column of money scannable at a glance.
 *
 * The balance keeps its sign. A credit row reads as a negative amount *and* is
 * labelled "Credit", so neither has to be inferred from the other.
 */
function OutstandingTable({ customers }: { customers: CustomerOutstandingRow[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span>Customer</span>
        <span className="text-right">Balance</span>
        <span className="w-24 text-right">Status</span>
      </div>

      <div className="divide-y divide-border">
        {customers.map((customer) => (
          <div
            key={customer.customerId}
            className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="min-w-0 truncate" title={customer.customerName}>
              {customer.customerName}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(customer.balance)}
            </span>
            <span className={`w-24 text-right text-xs ${statusClass(customer)}`}>
              {statusLabel(customer)}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * The side the API named, rendered in the report's own vocabulary.
 *
 * Read from `status` rather than from the sign: the server already classified each
 * row, and re-deciding it here would be a second implementation that could drift
 * from the ledger's rule.
 */
function statusLabel(customer: CustomerOutstandingRow): string {
  return customer.status === "customer_credit" ? "Credit" : "Receivable";
}

/** Muted styling for a credit row, so a liability is visible at a glance. */
function statusClass(customer: CustomerOutstandingRow): string {
  return customer.status === "customer_credit"
    ? "text-muted-foreground"
    : "text-foreground";
}

/**
 * A hint that says which way the net points.
 *
 * A negative net is not an error — it means the business owes more than it is owed
 * — and a bare negative figure beside the word "Net" would read like a mistake.
 * The summary prints receivable and credit separately, so this only names the
 * direction.
 */
function netHint(netOutstanding: number): string {
  if (netOutstanding < 0) {
    return "Business owes more";
  }

  return netOutstanding > 0 ? "Owed to the business" : "Nothing outstanding";
}

/**
 * The caption, which has to make one thing unambiguous.
 *
 * The balances are current whatever window is selected, so the caption leads with
 * "As of now" and only then names the period — which qualifies the *movement*
 * figure, not the balances. Describing the card by the selected range alone would
 * imply the money below it moved with the filter, and it does not.
 */
function describe(report: CustomerOutstandingReport): string {
  const { from, to } = report.period;

  if (from === null && to === null) {
    return "As of now — from the customer ledger, not invoice dues";
  }

  const window =
    from !== null && to !== null
      ? from === to
        ? from
        : `${from} to ${to}`
      : from !== null
        ? `from ${from}`
        : `up to ${to}`;

  return `As of now — movement in ${window}`;
}
