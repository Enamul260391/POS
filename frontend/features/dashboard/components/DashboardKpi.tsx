"use client";

import type { LucideIcon } from "lucide-react";

import { cn } from "cn";

export interface DashboardKpiProps {
  /** Short label above the value, e.g. "Sales today". */
  label: string;
  /** The headline value, usually a formatted string. */
  value: string;
  /** Optional supporting line, e.g. "12 invoices". */
  hint?: string;
  /** Leading icon, matching the icon-in-tile treatment on the cash drawer. */
  icon: LucideIcon;
  /** Tints the value, e.g. destructive for an empty register. */
  tone?: "default" | "muted" | "warning" | "success";
  className?: string;
}

/**
 * A single dashboard headline figure.
 *
 * Mirrors the `CashDrawerStats` tile — icon chip on the left, label above value
 * — so the Dashboard and the cash drawer share one visual vocabulary for
 * inline metrics. `tabular-nums` keeps a row of figures aligned as they
 * update, and `truncate` guarantees the tile never widens the grid.
 *
 * The value is always a pre-formatted string supplied by the caller; this
 * component performs no arithmetic of its own.
 */
export function DashboardKpi({
  label,
  value,
  hint,
  icon: Icon,
  tone = "default",
  className,
}: DashboardKpiProps) {
  return (
    <div
      className={cn(
        "flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2.5",
        className,
      )}
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
      </span>
      <span className="grid min-w-0 gap-0.5">
        <span className="truncate text-xs text-muted-foreground">{label}</span>
        <span
          className={cn(
            "truncate font-heading text-sm font-semibold tabular-nums",
            tone === "muted" && "text-muted-foreground",
            tone === "warning" && "text-destructive",
            tone === "success" && "text-success",
          )}
        >
          {value}
        </span>
        {hint && (
          <span className="truncate text-xs text-muted-foreground">{hint}</span>
        )}
      </span>
    </div>
  );
}
