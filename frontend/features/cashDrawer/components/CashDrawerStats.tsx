"use client";

import { memo } from "react";
import {
  ArrowDownLeft,
  ArrowUpRight,
  Banknote,
  Scale,
  Wallet,
} from "lucide-react";

import { formatMoney } from "@/features/pos/lib/format";
import { cn } from "cn";
import type { CashRegisterSummary } from "@/types/cashDrawer";

interface CashDrawerStatsProps {
  /** The aggregate `GET /cash-registers/{id}/summary` returned. */
  summary: CashRegisterSummary;
}

/**
 * Status cards above the drawer history.
 *
 * Every figure is the API's own: `opening_balance`, `total_in`, `total_out` and
 * `current_balance` come straight from `CashRegisterService::summary()`, which
 * aggregates the register's transactions in SQL. Nothing is re-summed on the
 * client — the loaded transaction page is paginated, so summing it here would
 * produce a different number than the one the API persists.
 *
 * `total_in` deliberately merges cash sales with manual cash in, and `total_out`
 * merges cash expenses with manual cash out, because that is exactly the
 * `INFLOW_TYPES` / `OUTFLOW_TYPES` split the backend reports. The individual
 * movements are itemised in the history table below.
 */
export const CashDrawerStats = memo(function CashDrawerStats({
  summary,
}: CashDrawerStatsProps) {
  const cards = [
    {
      label: "Opening float",
      value: formatMoney(summary.openingBalance),
      icon: Wallet,
      tone: "text-muted-foreground",
    },
    {
      label: "Cash in",
      value: `+${formatMoney(summary.totalIn)}`,
      icon: ArrowDownLeft,
      tone: "text-success",
    },
    {
      label: "Cash out",
      value: `-${formatMoney(summary.totalOut)}`,
      icon: ArrowUpRight,
      tone: summary.totalOut > 0 ? "text-destructive" : "text-muted-foreground",
    },
    {
      label: "Expected cash",
      value: formatMoney(summary.currentBalance),
      icon: Scale,
      tone: "text-foreground",
      strong: true,
    },
    {
      label: "Transactions",
      value: String(summary.transactionCount),
      icon: Banknote,
      tone: "text-muted-foreground",
    },
  ];

  return (
    <div
      aria-label="Drawer summary"
      className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5"
    >
      {cards.map((card) => (
        <div
          key={card.label}
          className={cn(
            "flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2.5",
            card.strong && "border-primary/40 bg-primary/5",
          )}
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted">
            <card.icon className="size-4 text-muted-foreground" aria-hidden="true" />
          </span>
          <span className="grid min-w-0 gap-0.5">
            <span className="truncate text-xs text-muted-foreground">
              {card.label}
            </span>
            <span
              className={cn(
                "truncate font-heading text-sm font-semibold tabular-nums",
                card.tone,
              )}
            >
              {card.value}
            </span>
          </span>
        </div>
      ))}
    </div>
  );
});
