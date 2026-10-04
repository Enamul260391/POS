"use client";

import { memo } from "react";

import { cn } from "cn";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type {
  CashMovementDirection,
  CashMovementRow,
  CashMovementTypeBreakdown,
  CashRegisterMovementReport,
} from "@/types/report";

interface CashRegisterMovementCardProps {
  /** The `GET /reports/cash-register-movements` response, already mapped. */
  report: CashRegisterMovementReport;
}

/**
 * Report: **cash register movement** — `GET /reports/cash-register-movements`.
 *
 * A pure function of one mapped report, so it issues **no request of its own**:
 * the screen loads it alongside the other reports and hands the object down.
 *
 * ## What these figures are, and what they deliberately are not
 *
 * These are the movements that **physically crossed the drawer**, and the API's
 * source of truth is `cash_register_transactions` — nothing else. That is the whole
 * distinction this card exists to make visible:
 *
 *  - A card, bank or cheque sale produced an invoice and a payment and **never
 *    touched the drawer**, so it is absent here.
 *  - A credit sale is a receivable, not cash, so it is absent too.
 *  - A bank-paid expense never left the drawer, so it is absent.
 *  - A refund paid out by bank transfer is absent; only the cash portion of a return
 *    appears, because only that is a drawer movement.
 *
 * So "Cash In" here is **not** "money the business collected", and this card is not a
 * cash-flow statement — it is the drawer ledger, read over the selected period.
 *
 * ## The opening float is shown, and never counted
 *
 * The `opening` / `closing` rows are markers, not movements: they record a balance
 * at a point in the shift, which is why the API reports them as
 * `direction: "non_movement"` and keeps them out of the cash totals — the drawer's
 * own arithmetic adds the float separately. The card therefore calls them out in
 * their own note rather than folding them into figures a user might add up, since a
 * float counted as an inflow would disagree with the drawer balance on the cash
 * screen.
 *
 * ## Nothing here is recomputed
 *
 * Cash in, cash out and the net are the API's own aggregates, read straight off the
 * payload. The breakdown and the listing describe the same population, so the card
 * never re-sums them: a client-side total is a second implementation in a weaker
 * number format, and the one that would silently disagree.
 *
 * ## No invented columns
 *
 * `cash_registers` has **no** name column, so a register is identified by its id
 * and shown as `#id`. No column anywhere holds a human label for a referenced
 * document, so the reference renders as the model name the API echoed (`Payment`,
 * `Expense`, `SaleReturn`) — never a fabricated description.
 */
export const CashRegisterMovementCard = memo(function CashRegisterMovementCard({
  report,
}: CashRegisterMovementCardProps) {
  const markers = report.byType.filter((row) => row.direction === "non_movement");
  const monetary = report.byType.filter(
    (row) => row.direction !== "non_movement",
  );

  return (
    <ReportCard
      title="Cash register movement"
      description="Cash that physically crossed the drawer in this period."
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
        <ReportMetric
          label="Cash in"
          value={formatMoney(report.totalCashIn)}
          hint="Received into the drawer"
        />
        <ReportMetric
          label="Cash out"
          value={formatMoney(report.totalCashOut)}
          hint="Paid out of the drawer"
        />
        <ReportMetric
          label="Net cash movement"
          value={formatMoney(report.netCashMovement)}
          hint="Cash in less cash out"
        />
        <ReportMetric
          label="Transactions"
          value={String(report.transactionCount)}
          hint={
            report.nonMovementCount > 0
              ? `${report.cashMovementCount} moved cash`
              : "Register rows recorded"
          }
        />
      </div>

      {report.transactionCount === 0 ? (
        <p className="text-xs text-muted-foreground">
          No cash-register movements were recorded in this period.
        </p>
      ) : (
        <>
          {monetary.length > 0 && (
            <>
              <p className="text-xs text-muted-foreground">By movement type.</p>
              <BreakdownTable rows={monetary} />
            </>
          )}

          {markers.length > 0 && <MarkerNote rows={markers} />}

          <p className="text-xs text-muted-foreground">
            Every movement recorded in this period, newest first.
          </p>
          <MovementTable movements={report.movements} />
        </>
      )}
    </ReportCard>
  );
});

/**
 * The per-type aggregate: type, direction, count, amount.
 *
 * The direction is a **named column** rather than a sign on the amount, because the
 * stored amounts are unsigned magnitudes and the sign is the server's
 * classification — rendering an outflow as a signed figure in this table would be
 * inventing a convention the schema does not have.
 */
function BreakdownTable({ rows }: { rows: CashMovementTypeBreakdown[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span>Type</span>
        <span className="text-right">Count</span>
        <span className="w-14 text-right">Flow</span>
        <span className="w-28 text-right">Amount</span>
      </div>

      <div className="divide-y divide-border">
        {rows.map((row) => (
          <div
            key={row.type}
            className="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="min-w-0 truncate" title={row.label}>
              {row.label}
            </span>
            <span className="text-right tabular-nums text-muted-foreground">
              {row.transactionCount}
            </span>
            <span
              className={cn(
                "w-14 text-right text-xs font-medium",
                directionClass(row.direction),
              )}
            >
              {row.direction === "in" ? "In" : "Out"}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(row.amount)}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * The period's movements: when, which drawer, what, how much.
 *
 * Plain markup and the same grid language as the cards beside it — no chart
 * dependency and no new library. `tabular-nums` is what makes a column of money
 * scannable at a glance.
 *
 * The reference and note appear inline beside the timestamp rather than as extra
 * columns, because `reference_type` is null for a manual cash in or out: a fixed
 * column would be empty for exactly the rows a cashier most wants to identify.
 */
function MovementTable({ movements }: { movements: CashMovementRow[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[minmax(0,1.3fr)_auto_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span>When</span>
        <span className="text-right">Register</span>
        <span className="w-24 text-right">Type</span>
        <span className="w-28 text-right">Amount</span>
      </div>

      <div className="divide-y divide-border">
        {movements.map((movement) => (
          <div
            key={movement.id}
            className="grid grid-cols-[minmax(0,1.3fr)_auto_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="min-w-0 truncate" title={describe(movement)}>
              {movement.occurredAt}
              {movement.reference && (
                <span className="ml-2 text-xs text-muted-foreground">
                  {movement.reference}
                </span>
              )}
              {movement.note && (
                <span className="ml-2 text-xs text-muted-foreground">
                  {movement.note}
                </span>
              )}
            </span>

            {/* `cash_registers` has no name column, so the id is the whole identity. */}
            <span className="text-right text-xs text-muted-foreground tabular-nums">
              #{movement.registerId}
            </span>

            <span
              className={cn(
                "w-24 truncate text-right text-xs font-medium",
                directionClass(movement.direction),
              )}
              title={`${movement.label} · ${movement.origin}`}
            >
              {movement.label}
            </span>

            {/* The sign here is a *display* of the named direction, not a
                re-derivation of it: the stored amount is an unsigned magnitude, and
                the server classified it. A plain ASCII hyphen is used rather than a
                typographic minus so the cell renders identically regardless of the
                font in use. */}
            <span className="w-28 text-right tabular-nums">
              {movement.direction === "out" && movement.amount > 0
                ? `-${formatMoney(movement.amount)}`
                : formatMoney(movement.amount)}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * Colour for a direction.
 *
 * Green for money in, red for money out, muted for the markers — which is what
 * keeps a row the user cannot add to a total from looking like one they can.
 */
function directionClass(direction: CashMovementDirection): string {
  switch (direction) {
    case "in":
      return "text-emerald-600 dark:text-emerald-400";
    case "out":
      return "text-rose-600 dark:text-rose-400";
    default:
      return "text-muted-foreground";
  }
}

/**
 * The hover text for a movement row: everything the narrow grid has no room for.
 *
 * Assembled only from fields the API genuinely sent. The operator may legitimately
 * be absent — `created_by` is `nullOnDelete`, and a movement recorded by a user of
 * another business is reported with the operator suppressed — so the sentence is
 * phrased to read correctly without one.
 */
function describe(movement: CashMovementRow): string {
  const parts = [
    movement.label,
    movement.occurredAt,
    `Register #${movement.registerId}`,
    movement.origin,
  ];

  if (movement.operator) {
    parts.push(`by ${movement.operator}`);
  }

  if (movement.reference) {
    parts.push(`reference ${movement.reference}`);
  }

  if (movement.note) {
    parts.push(movement.note);
  }

  return parts.join(" · ");
}

/**
 * Explains the markers instead of hiding them.
 *
 * An opening float and a closing count are real drawer rows, so dropping them
 * would make the listing disagree with `transactionCount`. But they carry no cash
 * direction, so they are called out separately with the reason — otherwise a user
 * would either add them to cash-in or think the report had lost rows.
 *
 * The amount is shown, and explicitly labelled as counted in neither direction, so
 * the figure is present without being available to add up.
 */
function MarkerNote({ rows }: { rows: CashMovementTypeBreakdown[] }) {
  const total = rows.reduce((sum, row) => sum + row.amount, 0);

  return (
    <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
      {rows.map((row) => row.label).join(" and ")} also recorded this period —{" "}
      {formatMoney(total)} of shift markers, counted as neither cash in nor cash
      out.
    </p>
  );
}
