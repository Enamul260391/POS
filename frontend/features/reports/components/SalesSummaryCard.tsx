"use client";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { ReportUnavailable } from "@/features/reports/components/ReportUnavailable";
import { formatMoney } from "@/features/pos/lib/format";
import { REPORT_UNAVAILABLE_REASONS } from "@/types/report";
import type { SalesReport } from "@/types/report";

interface SalesSummaryCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/**
 * Report: sales summary — `GET /reports/sales`.
 *
 * Every figure here is the API's own aggregate for the selected window; the
 * card adds nothing. `subtotal`, discount and tax are shown netted the way
 * `ReportService` returns them (`grand_total` is already net of the header
 * discount and inclusive of tax, so they are never re-applied here).
 *
 * The payment-method donut this card used to carry is **not** rendered: the
 * reports API returns a single sales total with no split by payment method, so
 * the panel below states that instead of a chart the server never sent.
 */
export function SalesSummaryCard({ sales }: SalesSummaryCardProps) {
  return (
    <ReportCard
      title="Sales summary"
      description={`${sales.invoiceCount} invoices · ${formatMoney(sales.grossSales)} net sales`}
      className="md:col-span-2"
    >
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric label="Invoices" value={sales.invoiceCount} />
        <ReportMetric
          label="Net sales"
          value={formatMoney(sales.grossSales)}
          hint={
            sales.discountTotal > 0
              ? `-${formatMoney(sales.discountTotal)} off · ${formatMoney(sales.taxTotal)} tax`
              : `${formatMoney(sales.taxTotal)} tax`
          }
        />
        <ReportMetric
          label="Gross profit"
          value="—"
          hint="Cost unavailable"
        />
        <ReportMetric
          label="Collected"
          value={formatMoney(sales.paidAmount)}
          hint={
            sales.dueAmount > 0 ? `${formatMoney(sales.dueAmount)} due` : "Fully paid"
          }
        />
      </div>

      <ReportUnavailable
        reason={REPORT_UNAVAILABLE_REASONS.paymentBreakdown}
        hint="A payment-method breakdown would need the sales report to group payments by method."
      />
    </ReportCard>
  );
}

