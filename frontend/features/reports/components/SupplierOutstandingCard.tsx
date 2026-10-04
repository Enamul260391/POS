"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type {
  SupplierOutstandingReport,
  SupplierOutstandingRow,
} from "@/types/report";

interface SupplierOutstandingCardProps {
  /** The `GET /reports/supplier-outstanding` response, already mapped. */
  report: SupplierOutstandingReport;
}

/**
 * Report: **supplier outstanding / payables** —
 * `GET /reports/supplier-outstanding`.
 *
 * A pure function of one mapped report, so it issues **no request of its own**:
 * the screen loads it alongside the other reports and hands the object down.
 *
 * ## These figures are the purchase flow's, computed server-side
 *
 * Every balance is `suppliers.opening_balance + SUM(purchases.due_amount)` for
 * that supplier over the whole history, aggregated in SQL. Nothing here is summed
 * from the rows on screen: the summary is read off the API's own aggregate,
 * because a client-side re-sum would be a second implementation that could drift
 * from the server's.
 *
 * Unlike the customer side there is no ledger behind this, and none is invented.
 * The `due_amount` column is a *current* figure here — the purchase flow rewrites
 * it as `grand_total - paid_amount` on every write that moves it, and the schema
 * has no supplier return or credit note that could leave it stale. That is why
 * summing it is the whole answer rather than an approximation of one.
 *
 * ## The date filter does not move these numbers
 *
 * A balance is a standing position, not a period total: an invoice paid off last
 * month is no longer owed today whether or not it falls inside the window. So the
 * summary below is not described by the selected range — only the movement figure
 * is, and it is shown as its own labelled metric rather than folded into the
 * totals. The caption says so outright rather than leaving a user to wonder why
 * changing the month left the money unchanged.
 *
 * ## Positive and negative are shown, not merged
 *
 * The rule is **positive = the business owes the supplier, negative = the supplier
 * owes the business.** Both directions appear: payable and credit are separate
 * summary figures with the net beside them, and every row carries the side the
 * API named it. Nothing is made absolute — a credit row still shows its negative
 * balance, because that is what the arithmetic says and the table must not
 * contradict it.
 *
 * ## No fabricated rows
 *
 * The listing is exactly what the API returned: only suppliers with a non-zero
 * balance (the settled ones are dropped in SQL), and only suppliers this business
 * actually owns. There is no top-N, because a payables register is a statement of
 * what is owed rather than a ranking — trimming it would hide a debt. An empty
 * list is stated as an explicit empty state rather than padded with a zero-valued
 * supplier that would look like data.
 */
export const SupplierOutstandingCard = memo(function SupplierOutstandingCard({
  report,
}: SupplierOutstandingCardProps) {
  const { suppliers } = report;

  return (
    <ReportCard
      title="Supplier outstanding"
      description={describe(report)}
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
        <ReportMetric
          label="Total payable"
          value={formatMoney(report.payable)}
          hint="Owed to suppliers"
        />
        <ReportMetric
          label="Supplier credit"
          value={formatMoney(report.supplierCredit)}
          hint="Held from suppliers"
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

      {suppliers.length > 0 ? (
        <>
          <p className="text-xs text-muted-foreground">
            {report.supplierCount} supplier
            {report.supplierCount === 1 ? "" : "s"} with a non-zero balance.
          </p>
          <OutstandingTable suppliers={suppliers} />
        </>
      ) : (
        <p className="text-xs text-muted-foreground">
          Every supplier account is settled — nothing is outstanding and no credit
          is held.
        </p>
      )}
    </ReportCard>
  );
});
/**
 * The listing: supplier, balance, status.
 *
 * Plain markup and the same grid language as the cards beside it — one figure per
 * supplier, no chart dependency to add. `tabular-nums` is what makes a column of
 * money scannable at a glance.
 *
 * The balance keeps its sign. A credit row reads as a negative amount *and* is
 * labelled "Credit", so neither has to be inferred from the other.
 */
function OutstandingTable({ suppliers }: { suppliers: SupplierOutstandingRow[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span>Supplier</span>
        <span className="text-right">Balance</span>
        <span className="w-24 text-right">Status</span>
      </div>

      <div className="divide-y divide-border">
        {suppliers.map((supplier) => (
          <div
            key={supplier.supplierId}
            className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="min-w-0 truncate" title={supplier.supplierName}>
              {supplier.supplierName}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(supplier.balance)}
            </span>
            <span className={`w-24 text-right text-xs ${statusClass(supplier)}`}>
              {statusLabel(supplier)}
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
 * from the purchase flow's rule.
 */
function statusLabel(supplier: SupplierOutstandingRow): string {
  return supplier.status === "supplier_credit" ? "Credit" : "Payable";
}

/** Muted styling for a credit row, so an asset is visible at a glance. */
function statusClass(supplier: SupplierOutstandingRow): string {
  return supplier.status === "supplier_credit"
    ? "text-muted-foreground"
    : "text-foreground";
}

/**
 * A hint that says which way the net points.
 *
 * A negative net is not an error — it means suppliers are owed more credit than
 * the business owes them — and a bare negative figure beside the word "Net" would
 * read like a mistake. The summary prints payable and credit separately, so this
 * only names the direction.
 */
function netHint(netOutstanding: number): string {
  if (netOutstanding < 0) {
    return "Suppliers are owed more";
  }

  return netOutstanding > 0 ? "Owed to suppliers" : "Nothing outstanding";
}

/**
 * The caption, which has to make one thing unambiguous.
 *
 * The balances are current whatever window is selected, so the caption leads with
 * "As of now" and only then names the period — which qualifies the *movement*
 * figure, not the balances. Describing the card by the selected range alone would
 * imply the money below it moved with the filter, and it does not.
 */
function describe(report: SupplierOutstandingReport): string {
  const { from, to } = report.period;

  if (from === null && to === null) {
    return "As of now — from unpaid purchase invoices";
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