"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { formatMoney } from "@/features/pos/lib/format";
import type { SalesReport, TopUser } from "@/types/report";

interface TopUsersCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/**
 * Report: sales by staff — `metrics.top_users` of `GET /reports/sales`.
 *
 * A pure function of the same sales response the summary card, both trend cards
 * and the three leaderboards beside it already read, so it issues **no request of
 * its own**: the card is here because the server published the figure, not
 * because the browser went and assembled one.
 *
 * **It is not built from `/users`.** The staff directory is a paginated
 * master-data list, so ranking it client-side would report a partial roster as
 * if it were the period's leaderboard — and it would have no revenue column at
 * all. `/sales` is paginated for the same reason and would report a partial
 * slice. The API already grouped, ordered, tie-broke and trimmed, so the list
 * arrives final and this component only formats it.
 *
 * **The figures are gross**, exactly like `total_sales` in the summary card:
 * `SUM(sales.grand_total)` per staff member, over the same invoices. Returns and
 * refunds are not subtracted here — they are reported on their own lines in the
 * sales summary, and net sales per staff member is a differently defined
 * question this report does not answer.
 *
 * **Invoices are invoices.** `invoiceCount` is the API's `COUNT(sales.id)`, so
 * one invoice is one row however many lines it carried or however many payments
 * settled it. Nothing here re-counts anything.
 *
 * **Unassigned sales are absent, and no placeholder is invented.** A sale with no
 * cashier recorded is still counted in `total_sales`, so this list deliberately
 * does not reconcile with the summary card beside it — and it must not be made
 * to. There is no "Unknown User" in the schema and none is fabricated here,
 * because a leaderboard showing a staff member who does not exist is a worse lie
 * than a visible shortfall. The empty state below says so plainly instead.
 *
 * The row layout is plain markup for the same reason the other leaderboards are
 * tables: one figure per person across a bounded list the user already chose,
 * the project's existing visual language, and no chart dependency to add.
 */
export const TopUsersCard = memo(function TopUsersCard({
  sales,
}: TopUsersCardProps) {
  const users = sales.topUsers;

  return (
    <ReportCard
      title="Sales by staff"
      description={describeWindow(sales.period.from, sales.period.to)}
      className="md:col-span-2"
    >
      {users.length > 0 ? (
        <StaffRanking users={users} />
      ) : (
        <p className="text-xs text-muted-foreground">{emptyMessage(sales)}</p>
      )}
    </ReportCard>
  );
});

/**
 * The ranked rows: rank, staff member, invoice count, gross revenue.
 *
 * The same four-column shape as the top-products, sales-by-category and
 * sales-by-customer leaderboards beside it, so all four read the same way —
 * `tabular-nums` is what makes a column of money scannable at a glance.
 */
function StaffRanking({ users }: { users: TopUser[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span aria-hidden="true">#</span>
        <span>Staff</span>
        <span className="text-right">Invoices</span>
        <span className="text-right">Gross sales</span>
      </div>

      <ol className="divide-y divide-border">
        {users.map((user, index) => (
          <li
            key={user.userId}
            className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="tabular-nums text-muted-foreground">{index + 1}</span>
            <span className="min-w-0 truncate" title={user.userName}>
              {user.userName}
            </span>
            <span className="text-right tabular-nums text-muted-foreground">
              {user.invoiceCount}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(user.totalSales)}
            </span>
          </li>
        ))}
      </ol>
    </div>
  );
}

/**
 * Why the ranking is empty, in the user's own terms.
 *
 * The two cases are different facts and are worded differently: "no sales at
 * all" means the period was quiet, while "no sale named a staff member" means
 * the period traded but this report could not attribute any of it — sales taken
 * without a cashier recorded. Neither is an error, and neither is answered with
 * a placeholder row the API never sent. A zero-valued staff row would be the
 * worst of the three answers: it would look like data, and it would name a
 * person who did not sell anything.
 */
function emptyMessage(sales: SalesReport): string {
  if (sales.invoiceCount === 0) {
    return "No sales were recorded in this period.";
  }

  return "No sales in this period could be attributed to a staff member.";
}

/**
 * The caption naming the window this ranking covers.
 *
 * Same wording rule as the daily, monthly, top-products, sales-by-category and
 * sales-by-customer cards: the card describes the period it was actually given,
 * and an open-ended window says so rather than implying one. The period comes
 * from the same `sales` object as every other card, so no separate date filter
 * exists here.
 */
function describeWindow(from: string | null, to: string | null): string {
  if (from === null || to === null) {
    return "Sales by staff — select a date range";
  }

  return from === to
    ? `Sales by staff — ${from}`
    : `Sales by staff — ${from} to ${to}`;
}
