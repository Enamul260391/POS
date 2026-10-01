"use client";

import type { ReactNode } from "react";

import { cn } from "cn";

export interface ReportCardProps {
  /** Card title, read as a heading by screen readers via the `h2` default. */
  title: string;
  /** Optional caption shown under the title. */
  description?: string;
  /** Optional top-right content, e.g. an export button. */
  action?: ReactNode;
  className?: string;
  children: ReactNode;
}

/**
 * A bordered report card shell shared by every entry on `/reports`.
 *
 * Lifts the header (title + caption + action) so each report section only has to
 * render its own metrics, chart or table. `min-w-0` lets the card shrink below
 * its content size inside the flex shell without overflowing the dashboard grid.
 */
export function ReportCard({
  title,
  description,
  action,
  className,
  children,
}: ReportCardProps) {
  return (
    <section
      className={cn(
        "flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5",
        className,
      )}
    >
      <header className="flex min-w-0 items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="font-heading text-sm font-semibold tracking-tight">
            {title}
          </h2>
          {description && (
            <p className="mt-0.5 max-w-md truncate text-xs text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </header>
      {children}
    </section>
  );
}
