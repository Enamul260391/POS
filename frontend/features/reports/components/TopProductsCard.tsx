"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import type { SalesReport, TopProduct } from "@/types/report";

interface TopProductsCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/**
 * Report: top selling products — `metrics.top_products` of `GET /reports/sales`.
 *
 * The card used to state that no backend source existed for a product
 * ranking. It does now: `ReportService::sales()` groups its own sales
 * population all the way down to the sale lines and returns one row per
 * product, already ordered by gross revenue and already cut to a top N. So
 * this card is a pure function of the same response the summary and trend
 * cards read, and it issues **no request of its own**.
 *
 * The ranking is never rebuilt here — not from `/products`, and not by paging
 * through `/sales`. `/products` is a catalogue and `/sales` is paginated, so
 * either would report a partial slice as if it were the period's leaderboard.
 * The API already grouped, ordered, tie-broke and trimmed, so the list arrives
 * final and this component only formats it.
 *
 * **The figures are gross**, exactly like `total_sales` in the summary card
 * beside it: `SUM(sale_items.total)` per product. Returns and refunds are not
 * subtracted here — they are reported on their own lines in the sales summary,
 * and net product revenue is a differently defined question this report does not
 * answer.
 *
 * The row layout is plain markup rather than a chart, for the same reason the
 * daily and monthly trends are CSS: one number per product across a bounded
 * list the user already chose, the project's existing visual language, and no
 * chart dependency to add.
 */
export const TopProductsCard = memo(function TopProductsCard({
  sales,
}: TopProductsCardProps) {
  const products = sales.topProducts;

  return (
    <ReportCard
      title="Top selling products"
      description={describeWindow(sales.period.from, sales.period.to)}
      className="md:col-span-2"
    >
      {products.length > 0 ? (
        <ProductRanking products={products} />
      ) : (
        <p className="text-xs text-muted-foreground">
          {sales.invoiceCount > 0
            ? "No product lines were sold in this period."
            : "No sales were recorded in this period."}
        </p>
      )}
    </ReportCard>
  );
});

/**
 * The ranked rows: rank, product, units sold, gross revenue.
 *
 * A plain table rather than a flex row of bars: the revenue figure is the thing
 * being compared, and `tabular-nums` is what makes a column of money readable
 * at a glance — the same treatment the payment breakdown and both trend cards
 * give their amounts.
 */
function ProductRanking({ products }: { products: TopProduct[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span aria-hidden="true">#</span>
        <span>Product</span>
        <span className="text-right">Units</span>
        <span className="text-right">Gross sales</span>
      </div>

      <ol className="divide-y divide-border">
        {products.map((product, index) => (
          <li
            key={product.productId}
            className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="tabular-nums text-muted-foreground">{index + 1}</span>
            <span className="min-w-0 truncate" title={product.productName}>
              {product.productName}
            </span>
            <span className="text-right tabular-nums text-muted-foreground">
              {formatQuantity(product.quantity)}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(product.totalSales)}
            </span>
          </li>
        ))}
      </ol>
    </div>
  );
}

/**
 * The caption naming the window this ranking covers.
 *
 * Same wording rule as the daily and monthly trend cards: the card describes
 * the period it was actually given, and an open-ended window says so rather
 * than implying one. The period comes from the same `sales` object as every
 * other card, so no separate date filter exists here.
 */
function describeWindow(from: string | null, to: string | null): string {
  if (from === null || to === null) {
    return "Best sellers by revenue — select a date range";
  }

  return from === to
    ? `Best sellers by revenue — ${from}`
    : `Best sellers by revenue — ${from} to ${to}`;
}

