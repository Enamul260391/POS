"use client";

import {
  Boxes,
  Coins,
  PackageX,
  TriangleAlert,
  type LucideIcon,
} from "lucide-react";

import type { InventorySummary } from "@/features/inventory/lib/stock";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { cn } from "cn";

interface InventoryStatsProps {
  /** Memoised summary from the screen — recomputed only when stock changes. */
  summary: InventorySummary;
}

type StatTone = "neutral" | "warning" | "destructive";

interface StatCardProps {
  label: string;
  value: string;
  hint: string;
  icon: LucideIcon;
  tone: StatTone;
}

/** Single headline metric; tinted tones make problem counts impossible to miss. */
function StatCard({ label, value, hint, icon: Icon, tone }: StatCardProps) {
  return (
    <div
      className={cn(
        "flex items-start gap-3 rounded-lg border border-border bg-card p-4",
        tone === "warning" && "border-warning/50 bg-warning/10",
        tone === "destructive" && "border-destructive/40 bg-destructive/5",
      )}
    >
      <span
        className={cn(
          "flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground",
          tone === "warning" && "bg-warning/15 text-warning",
          tone === "destructive" && "bg-destructive/10 text-destructive",
        )}
      >
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <div className="min-w-0">
        <p className="text-xs font-medium text-muted-foreground">{label}</p>
        <p className="text-xl font-semibold tracking-tight tabular-nums">
          {value}
        </p>
        <p className="text-xs text-muted-foreground">{hint}</p>
      </div>
    </div>
  );
}

/**
 * Inventory headline numbers (Phase 7): current stock, stock value, low stock
 * and out of stock — the four facts the screen is built around.
 *
 * Low/out cards only take their warning/destructive tint while the count is
 * above zero, so a healthy inventory reads calm and a problem row jumps out.
 */
export function InventoryStats({ summary }: InventoryStatsProps) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Stock summary">
      <StatCard
        label="Current stock"
        value={formatQuantity(summary.totalUnits)}
        hint={`${summary.productsInStock} of ${summary.trackedCount} products in stock`}
        icon={Boxes}
        tone="neutral"
      />
      <StatCard
        label="Stock value"
        value={formatMoney(summary.stockValue)}
        hint="Value of stock at cost price"
        icon={Coins}
        tone="neutral"
      />
      <StatCard
        label="Low stock"
        value={formatQuantity(summary.lowStock.length)}
        hint="At or below their threshold"
        icon={TriangleAlert}
        tone={summary.lowStock.length > 0 ? "warning" : "neutral"}
      />
      <StatCard
        label="Out of stock"
        value={formatQuantity(summary.outOfStock.length)}
        hint="Nothing on hand right now"
        icon={PackageX}
        tone={summary.outOfStock.length > 0 ? "destructive" : "neutral"}
      />
    </div>
  );
}