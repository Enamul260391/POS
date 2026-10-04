"use client";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { formatMoney } from "@/features/pos/lib/format";
import type { ProfitLossReport, SalesReport } from "@/types/report";
import { cn } from "cn";

interface SalesSummaryCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
  /**
   * The `GET /reports/profit-loss` response, for the gross profit line.
   *
   * Taken from here rather than from `sales` because gross profit is a
   * cost-derived figure and only the profit-loss report carries cost of goods.
   */
  profitLoss: ProfitLossReport;
}

/**
 * Report: sales summary — `GET /reports/sales`.
 *
 * Every figure here is the API's own aggregate for the selected window; the
 * card adds nothing. `subtotal`, discount and tax are shown netted the way
 * `ReportService` returns them (`grand_total` is already net of the header
 * discount and inclusive of tax, so they are never re-applied here).
 *
 * Gross and net are kept apart on purpose. `total_sales` is what the invoices
 * were worth and `net_sales` is that figure less the returns dated inside the
 * window, so a card that showed only one of them would either hide the returns
 * or quietly relabel gross as net. When there are no returns the two are equal
 * and the card says so rather than repeating the same number twice.
 *
 * The payment-method split is the API's too: `metrics.payment_breakdown`
 * groups the `payments` of exactly these invoices by method, so the card only
 * reads it off and turns each amount into a share for the bar beside it. A
 * window with no payments renders an explicit empty state rather than a
 * fabricated "100% cash".
 *
 * Gross profit is the one figure this card borrows from `profitLoss`: it is
 * cost-derived, and `/reports/sales` carries no cost of goods at all.
 */
export function SalesSummaryCard({ sales, profitLoss }: SalesSummaryCardProps) {
  return (
    <ReportCard
      title="Sales summary"
      description={`${sales.invoiceCount} invoices · ${formatMoney(sales.grossSales)} gross sales`}
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric label="Invoices" value={sales.invoiceCount} />
        <ReportMetric
          label="Gross sales"
          value={formatMoney(sales.grossSales)}
          hint={
            sales.discountTotal > 0
              ? `-${formatMoney(sales.discountTotal)} off · ${formatMoney(sales.taxTotal)} tax`
              : `${formatMoney(sales.taxTotal)} tax`
          }
        />
        <ReportMetric
          label="Net sales"
          value={formatMoney(sales.netSales)}
          hint={
            sales.returnsCount > 0
              ? `less ${formatMoney(sales.returnsAmount)} across ${sales.returnsCount} return${sales.returnsCount === 1 ? "" : "s"}`
              : "No returns in period"
          }
        />
        <ReportMetric
          label="Gross profit"
          value={
            profitLoss.grossProfit === null
              ? "—"
              : formatMoney(profitLoss.grossProfit)
          }
          hint={
            profitLoss.grossMarginPercent === null
              ? "Cost unavailable"
              : `${profitLoss.grossMarginPercent}% margin`
          }
        />
      </div>

      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric
          label="Collected"
          value={formatMoney(sales.paidAmount)}
          hint={
            sales.dueAmount > 0 ? `${formatMoney(sales.dueAmount)} due` : "Fully paid"
          }
        />
        <ReportMetric
          label="Refunded"
          value={formatMoney(sales.refundAmount)}
          hint={
            sales.refundCount > 0
              ? `${sales.refundCount} refund payment${sales.refundCount === 1 ? "" : "s"}`
              : "No refunds in period"
          }
        />
        <ReportMetric
          label="Net collected"
          value={formatMoney(sales.netCollectedAmount)}
          hint="Collected less refunded"
        />
        <ReportMetric
          label="Customer outstanding"
          value={formatMoney(sales.customerOutstanding)}
          hint="From the customer ledger"
        />
      </div>

      {sales.byPaymentMethod.length > 0 ? (
        <div className="pt-1">
          <p className="mb-2 text-xs font-medium text-muted-foreground">
            Collected by payment method
          </p>
          <div className="space-y-3">
            {sales.byPaymentMethod.map((row, index) => (
              <div
                key={row.method}
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
      ) : (
        <p className="text-xs text-muted-foreground">
          No payments were recorded against these invoices.
        </p>
      )}
    </ReportCard>
  );
}

