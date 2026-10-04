"use client";

import { memo, useMemo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import { cn } from "cn";
import type { MonthlySalesPoint, SalesReport } from "@/types/report";

interface MonthlySalesCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/** Height of the tallest month's bar, in percent of the plot area. */
const PEAK_PERCENT = 100;

/**
 * A month with no sales still occupies its slot, so a quiet month reads as zero
 * rather than as a missing month.
 */
const ZERO_PERCENT = 2;

/**
 * Report: monthly sales trend — `metrics.monthly_series` of `GET /reports/sales`.
 *
 * The card used to state that no backend source existed for a per-month series.
 * It does now: `ReportService::sales()` groups its own sales population by the
 * calendar month of `sale_date` and returns one point per month of the requested
 * window, so this card is a pure function of the same response the summary and
 * daily cards read. It issues no request of its own and never reconstructs the
 * series by paging through `/sales` — that endpoint is paginated, so summing it
 * would report the first few hundred invoices of the period as if it were the
 * whole one.
 *
 * **The axis is the server's.** Months with no sales arrive as `"0.00"` and are
 * drawn as a flat bar rather than skipped: skipping them would compress the
 * period and make a quiet quarter look busy. Months the range only partly covers
 * are present too — the API's window decides the axis, not a full-month test in
 * the browser.
 *
 * **The month stays a string.** `YYYY-MM` is a calendar identifier, and it is
 * rendered from its parts rather than through `new Date(...)`, which would parse
 * it as UTC and could label January as December west of Greenwich.
 *
 * **Gross, like the summary beside it.** Each point is the same reading as
 * `total_sales`, so the bars sum to the headline number. Returns and refunds are
 * reported on their own lines in the sales summary and are deliberately not
 * subtracted here — monthly net sales is a different, separately defined report.
 *
 * The chart is plain CSS for the same reason the daily trend's is: one number per
 * month across a window the user already chose, the project's existing visual
 * language, and no chart dependency to add.
 */
export const MonthlySalesCard = memo(function MonthlySalesCard({
  sales,
}: MonthlySalesCardProps) {
  const points = sales.monthlySales;
  const summary = useMemo(() => summarise(points), [points]);

  return (
    <ReportCard
      title="Monthly sales"
      description={describeWindow(sales.period.from, sales.period.to)}
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric
          label="Gross sales"
          value={formatMoney(sales.grossSales)}
          hint="Same figure as the summary"
        />
        <ReportMetric
          label="Best month"
          value={
            summary.bestMonth === null ? "—" : formatMoney(summary.bestMonth.totalSales)
          }
          hint={
            summary.bestMonth === null ? "No sales in period" : shortMonth(summary.bestMonth.month)
          }
        />
        <ReportMetric
          label="Trading months"
          value={summary.tradingMonths}
          hint={`of ${points.length} in period`}
        />
        <ReportMetric
          label="Quiet months"
          value={summary.quietMonths}
          hint={summary.quietMonths > 0 ? "No sales recorded" : "Every month had sales"}
        />
      </div>

      {points.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          {sales.period.from === null || sales.period.to === null
            ? "Select a date range to see the sales trend by month."
            : "No sales were recorded in this period."}
        </p>
      ) : (
        <Trend points={points} peak={summary.peak} />
      )}
    </ReportCard>
  );
});

/** The `month -> totalSales` bar chart, one column per month of the window. */
function Trend({
  points,
  peak,
}: {
  points: MonthlySalesPoint[];
  peak: number;
}) {
  return (
    <div className="pt-1">
      <div
        className="flex h-32 items-end gap-px"
        role="img"
        aria-label={`Monthly gross sales across ${points.length} months, peaking at ${formatMoney(peak)}`}
      >
        {points.map((point) => {
          const percent =
            peak > 0 && point.totalSales > 0
              ? Math.max((point.totalSales / peak) * PEAK_PERCENT, ZERO_PERCENT)
              : ZERO_PERCENT;

          return (
            <div
              key={point.month}
              className="group flex h-full min-w-0 flex-1 flex-col justify-end"
            >
              <div
                className={cn(
                  "w-full rounded-t-xs transition-colors",
                  point.totalSales === 0
                    ? "bg-muted"
                    : "bg-chart-1/70 group-hover:bg-chart-1",
                )}
                style={{ height: `${percent}%` }}
                title={`${shortMonth(point.month)} · ${formatMoney(point.totalSales)}`}
              />
            </div>
          );
        })}
      </div>

      <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
        <span>{shortMonth(points[0]?.month)}</span>
        <span>{shortMonth(points[points.length - 1]?.month)}</span>
      </div>
    </div>
  );
}

/** What the header metrics above the chart are read off. */
interface TrendSummary {
  /** The largest point the API sent, used to scale the bars. */
  peak: number;
  /** The month carrying it, or `null` when nothing sold. */
  bestMonth: MonthlySalesPoint | null;
  tradingMonths: number;
  quietMonths: number;
}

/**
 * Peak, best month and the quiet-month count, all read off the API's own points.
 *
 * `bestMonth` is the *first* point that reaches the peak, so a tie shows the
 * earlier month rather than whichever the array happened to end on. Nothing is
 * averaged here: an average month is a figure the report does not publish.
 */
function summarise(points: MonthlySalesPoint[]): TrendSummary {
  let peak = 0;
  let bestMonth: MonthlySalesPoint | null = null;
  let tradingMonths = 0;

  for (const point of points) {
    if (point.totalSales > 0) {
      tradingMonths += 1;
    }
    if (point.totalSales > peak) {
      peak = point.totalSales;
      bestMonth = point;
    }
  }

  return { peak, bestMonth, tradingMonths, quietMonths: points.length - tradingMonths };
}

/**
 * `2026-01` → `Jan 2026`, read as a *local* calendar month.
 *
 * The parts are handed to the `Date` constructor rather than the whole string:
 * `new Date("2026-01")` is parsed as UTC midnight, which for a viewer west of
 * Greenwich is December — the same trap `toApiDate` exists to avoid. The parts
 * only pick a label; the value itself is never treated as an instant.
 */
function shortMonth(month: string | undefined): string {
  if (!month) {
    return "";
  }

  const [year, index] = month.split("-").map(Number);
  const parsed = new Date(year, (index ?? 1) - 1, 1);

  return Number.isNaN(parsed.getTime())
    ? month
    : parsed.toLocaleDateString(undefined, { month: "short", year: "numeric" });
}

/** The caption naming the window the axis covers. */
function describeWindow(from: string | null, to: string | null): string {
  if (from === null || to === null) {
    return "Sales trend by month — select a date range";
  }

  return from === to
    ? `Sales trend by month — ${from}`
    : `Sales trend by month — ${from} to ${to}`;
}

