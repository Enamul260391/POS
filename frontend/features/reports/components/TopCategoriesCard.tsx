"use client";

import { memo } from "react";

import { ReportCard } from "@/features/reports/components/ReportCard";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import type { SalesReport, TopCategory } from "@/types/report";

interface TopCategoriesCardProps {
  /** The `GET /reports/sales` response, already mapped. */
  sales: SalesReport;
}

/**
 * Report: sales by category — `metrics.top_categories` of `GET /reports/sales`.
 *
 * A pure function of the same sales response the summary card, both trend cards
 * and the top-products leaderboard already read, so it issues **no request of
 * its own**: the card is here because the server published the figure, not
 * because the browser went and assembled one.
 *
 * The ranking is never rebuilt here — not from `/categories`, and not by paging
 * through `/products`. `/products` is a paginated document list and
 * `/categories` is a catalogue, so either would report a partial slice as if it
 * were the period's leaderboard. The API already grouped, ordered, tie-broke and
 * trimmed, so the list arrives final and this component only formats it.
 *
 * **The figures are gross**, exactly like `total_sales` in the summary card
 * beside it: `SUM(sale_items.total)` per category, over the same invoices.
 * Returns and refunds are not subtracted here — they are reported on their own
 * lines in the sales summary, and net sales per category is a differently
 * defined question this report does not answer.
 *
 * **Lines with no category are absent, not renamed.** A service line has no
 * product, and a product with no category has nothing to be filed under, so both
 * are dropped by the API. There is no "Uncategorized" row here to render, and
 * the empty state below says so rather than inventing one.
 *
 * The row layout is plain markup for the same reason the daily and monthly
 * trends are CSS and the product leaderboard is a table: one number per category
 * across a bounded list the user already chose, the project's existing visual
 * language, and no chart dependency to add.
 */
export const TopCategoriesCard = memo(function TopCategoriesCard({
  sales,
}: TopCategoriesCardProps) {
  const categories = sales.topCategories;

  return (
    <ReportCard
      title="Sales by category"
      description={describeWindow(sales.period.from, sales.period.to)}
      className="md:col-span-2"
    >
      {categories.length > 0 ? (
        <CategoryRanking categories={categories} />
      ) : (
        <p className="text-xs text-muted-foreground">{emptyMessage(sales)}</p>
      )}
    </ReportCard>
  );
});

/**
 * The ranked rows: rank, category, units sold, gross revenue.
 *
 * The same four-column shape as the top-products leaderboard beside it, so the
 * two read the same way — `tabular-nums` is what makes a column of money
 * scannable at a glance.
 */
function CategoryRanking({ categories }: { categories: TopCategory[] }) {
  return (
    <div className="pt-1">
      <div className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 border-b border-border pb-1.5 text-xs font-medium text-muted-foreground">
        <span aria-hidden="true">#</span>
        <span>Category</span>
        <span className="text-right">Units</span>
        <span className="text-right">Gross sales</span>
      </div>

      <ol className="divide-y divide-border">
        {categories.map((category, index) => (
          <li
            key={category.categoryId}
            className="grid grid-cols-[1.5rem_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 py-2 text-sm"
          >
            <span className="tabular-nums text-muted-foreground">{index + 1}</span>
            <span className="min-w-0 truncate" title={category.categoryName}>
              {category.categoryName}
            </span>
            <span className="text-right tabular-nums text-muted-foreground">
              {formatQuantity(category.quantity)}
            </span>
            <span className="w-28 text-right tabular-nums">
              {formatMoney(category.totalSales)}
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
 * all" means the period was quiet, while "no categorised lines" means the
 * period had sales this report could not attribute. Neither is an error, and
 * neither is answered with a placeholder row the API never sent.
 */
function emptyMessage(sales: SalesReport): string {
  if (sales.invoiceCount === 0) {
    return "No sales were recorded in this period.";
  }

  return "No sales in this period could be attributed to a category.";
}

/**
 * The caption naming the window this ranking covers.
 *
 * Same wording rule as the daily, monthly and top-products cards: the card
 * describes the period it was actually given, and an open-ended window says so
 * rather than implying one. The period comes from the same `sales` object as
 * every other card, so no separate date filter exists here.
 */
function describeWindow(from: string | null, to: string | null): string {
  if (from === null || to === null) {
    return "Sales by category — select a date range";
  }

  return from === to
    ? `Sales by category — ${from}`
    : `Sales by category — ${from} to ${to}`;
}
