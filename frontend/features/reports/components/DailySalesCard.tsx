"use client";

import { memo, useMemo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import { cn } from "cn";
import type { DailySalesPoint, SalesReport } from "@/types/report";

interface DailySalesCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/** Height of the tallest day's bar, in percent of the plot area. */
const PEAK_PERCENT = 100;

/**
 * A bar with no sales still occupies its slot, so a quiet day reads as zero
 * rather than as a missing day.
 */
const ZERO_PERCENT = 2;

/**
 * Report: daily sales trend — `metrics.daily_series` of `GET /reports/sales`.
 *
 * The card used to state that no backend source existed for a per-day trend.
 * It does now: `ReportService::sales()` groups its own sales population by
 * `sale_date` and returns one point per day of the requested window, so this
 * card is a pure function of the same response the summary card reads. It issues
 * no request of its own and never reconstructs the series from `/sales` — that
 * endpoint is paginated, so summing it would report the first few hundred
 * invoices of the period as if it were the whole one.
 *
 * **The axis is the server's.** Days with no sales arrive as `"0.00"` and are
 * drawn as a flat bar rather than skipped: skipping them would compress the
 * period and make a quiet week look busy. The dates are the API's bare
 * `YYYY-MM-DD` strings, parsed as *local* calendar days — `new Date("2026-10-01")`
 * would parse as UTC midnight and render as the 30th west of Greenwich.
 *
 * **Gross, like the summary beside it.** Each point is the same reading as
 * `total_sales`, so the bars sum to the headline number. Returns and refunds are
 * reported on their own lines in the sales summary and are deliberately not
 * subtracted here — daily net sales is a different, separately defined report.
 *
 * The chart is plain CSS rather than a charting library: the series is one
 * number per day across a window the user already chose, a flex row of bars with
 * tooltips says exactly that, and the project has no chart dependency to reuse.
 */
export const DailySalesCard = memo(function DailySalesCard({
  sales,
}: DailySalesCardProps) {
  const points = sales.dailySales;
  const summary = useMemo(() => summarise(points), [points]);

  return (
    <ReportCard
      title="Daily sales"
      description={describeWindow(sales.period.from, sales.period.to)}
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric
          label="Gross sales"
          value={formatMoney(sales.grossSales)}
          hint="Same figure as the summary"
        />
        <ReportMetric
          label="Best day"
          value={
            summary.bestDay === null ? "—" : formatMoney(summary.bestDay.totalSales)
          }
          hint={summary.bestDay === null ? "No sales in period" : summary.bestDay.date}
        />
        <ReportMetric
          label="Trading days"
          value={summary.tradingDays}
          hint={`of ${points.length} in period`}
        />
        <ReportMetric
          label="Quiet days"
          value={summary.quietDays}
          hint={summary.quietDays > 0 ? "No sales recorded" : "Every day had sales"}
        />
      </div>

      {points.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          {sales.period.from === null || sales.period.to === null
            ? "Select a date range to see the sales trend by day."
            : "No sales were recorded in this period."}
        </p>
      ) : (
        <Trend points={points} peak={summary.peak} />
      )}
    </ReportCard>
  );
});

/** The `date -> totalSales` bar chart, one column per day of the window. */
function Trend({
  points,
  peak,
}: {
  points: DailySalesPoint[];
  peak: number;
}) {
  return (
    <div className="pt-1">
      <div
        className="flex h-32 items-end gap-px"
        role="img"
        aria-label={`Daily gross sales across ${points.length} days, peaking at ${formatMoney(peak)}`}
      >
        {points.map((point) => {
          const percent =
            peak > 0 && point.totalSales > 0
              ? Math.max((point.totalSales / peak) * PEAK_PERCENT, ZERO_PERCENT)
              : ZERO_PERCENT;

          return (
            <div
              key={point.date}
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
                title={`${point.date} · ${formatMoney(point.totalSales)}`}
              />
            </div>
          );
        })}
      </div>

      <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
        <span>{shortDay(points[0]?.date)}</span>
        <span>{shortDay(points[points.length - 1]?.date)}</span>
      </div>
    </div>
  );
}

/** What the header metrics above the chart are read off. */
interface TrendSummary {
  /** The largest point the API sent, used to scale the bars. */
  peak: number;
  /** The day carrying it, or `null` when nothing sold. */
  bestDay: DailySalesPoint | null;
  tradingDays: number;
  quietDays: number;
}

/**
 * Peak, best day and the quiet-day count, all read off the API's own points.
 *
 * `bestDay` is the *first* point that reaches the peak, so a tie shows the
 * earlier day rather than whichever the array happened to end on. Nothing is
 * averaged here: an average day is a figure the report does not publish.
 */
function summarise(points: DailySalesPoint[]): TrendSummary {
  let peak = 0;
  let bestDay: DailySalesPoint | null = null;
  let tradingDays = 0;

  for (const point of points) {
    if (point.totalSales > 0) {
      tradingDays += 1;
    }
    if (point.totalSales > peak) {
      peak = point.totalSales;
      bestDay = point;
    }
  }

  return { peak, bestDay, tradingDays, quietDays: points.length - tradingDays };
}

/**
 * `2026-10-01` → `Oct 1`, read as a local calendar day.
 *
 * The parts are handed to the `Date` constructor rather than the whole string:
 * `new Date("2026-10-01")` is parsed as UTC midnight, which is the 30th for any
 * viewer west of Greenwich — the same trap `toApiDate` exists to avoid.
 */
function shortDay(date: string | undefined): string {
  if (!date) {
    return "";
  }

  const [year, month, day] = date.split("-").map(Number);
  const parsed = new Date(year, (month ?? 1) - 1, day ?? 1);

  return Number.isNaN(parsed.getTime())
    ? date
    : parsed.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

/** The caption naming the window the axis covers. */
function describeWindow(from: string | null, to: string | null): string {
  if (from === null || to === null) {
    return "Sales trend by day — select a date range";
  }

  return from === to
    ? `Sales trend by day — ${from}`
    : `Sales trend by day — ${from} to ${to}`;
}

