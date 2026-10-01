"use client";

import { BarChart3 } from "lucide-react";

import { cn } from "cn";

export interface ReportUnavailableProps {
  /**
   * The reason the API cannot answer this report, shown verbatim.
   *
   * Passed in rather than hard-coded so the copy lives with the gap it
   * documents (`REPORT_UNAVAILABLE_REASONS` in `@/types/report`) instead of
   * being scattered across the cards.
   */
  reason: string;
  /** Optional second line, e.g. what would be needed to fill it in. */
  hint?: string;
  className?: string;
}

/**
 * The panel a report card shows instead of a figure the API did not send.
 *
 * The `/reports` screen draws four breakdowns the reports API cannot currently
 * answer (payment methods, daily sales, monthly sales, top products) plus the
 * low-stock item list. Rather than reconstructing them from the paginated
 * `/sales` and `/products` document lists — which would report a partial period
 * as if it were the whole one — the card states the gap, so a number is never
 * on screen that the server did not produce.
 *
 * It reuses the dashed-border empty-state look the report cards already use, so
 * the grid keeps its rhythm and the cards stay mounted.
 */
export function ReportUnavailable({
  reason,
  hint,
  className,
}: ReportUnavailableProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center gap-1.5 rounded-lg border",
        "border-dashed border-border px-6 py-8 text-center",
        className,
      )}
    >
      <BarChart3 className="size-6 text-muted-foreground" aria-hidden="true" />
      <p className="text-sm font-medium">Not available from the API</p>
      <p className="max-w-md text-xs text-muted-foreground">{reason}</p>
      {hint && (
        <p className="max-w-md text-xs text-muted-foreground">{hint}</p>
      )}
    </div>
  );
}
