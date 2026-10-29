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
  tone?: "default" | "muted" | "warning" | "success" | "danger";
  /** Optional DOM id for the tile. */
  id?: string;
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
  id,
  className,
}: DashboardKpiProps) {
  const accent =
    tone === "success"
      ? "from-emerald-500 to-teal-400"
      : tone === "warning"
      ? "from-rose-500 to-orange-400"
      : tone === "muted"
      ? "from-slate-400 to-slate-300"
      : "from-violet-500 to-indigo-400";

  return (
    <div
      id={id}
      className={cn(
        "group flex items-center gap-8 rounded-xl border border-border bg-gradient-to-br from-white to-slate-50 px-4 py-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:from-slate-800 dark:to-slate-900 h-[128px]",
        className,
      )}
    >
      <span
        className={cn(
          "flex size-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-md transition-transform duration-200 group-hover:scale-110",
          accent,
        )}
      >
        <Icon className="size-5" aria-hidden="true" />
      </span>
      <span className="grid min-w-0 gap-2">
        <span
          className={cn(
            "truncate text-base font-bold",
            tone === "warning" && "text-amber-600",
            tone === "success" && "text-emerald-600",
            tone === "muted" && "text-amber-600",
            tone === "danger" && "text-red-600",
            tone === "default" && "text-orange-600",
          )}
        >
          {label}
        </span>
        <span
          className={cn(
            "truncate font-heading text-2xl font-extrabold tabular-nums",
            tone === "muted" && "text-amber-500",
            tone === "danger" && "text-red-600",
            tone === "warning" && "text-amber-500",
            tone === "success" && "text-emerald-600",
            tone === "default" && "text-orange-600",
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
