"use client";

import type { ReactNode } from "react";

import { cn } from "cn";

export interface ReportMetricProps {
  /** Short label above the value. */
  label: string;
  /** The headline value, usually a formatted string. */
  value: ReactNode;
  /** Optional hint below, e.g. a margin percentage. */
  hint?: string;
  className?: string;
}

/**
 * A small label / value / hint stack used inside report cards.
 *
 * Mirrors the `Fact` pill the cash-drawer screen uses so the dashboards share a
 * single vocabulary for inline metrics. Numbers use `tabular-nums` so columns
 * of figures stay aligned as they update.
 */
export function ReportMetric({
  label,
  value,
  hint,
  className,
}: ReportMetricProps) {
  return (
    <div className={cn("flex flex-col gap-0.5", className)}>
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="font-heading text-sm font-semibold tabular-nums">
        {value}
      </span>
      {hint && (
        <span className="text-xs text-muted-foreground">{hint}</span>
      )}
    </div>
  );
}
