"use client";

import { memo } from "react";
import { Boxes } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type {
  StockMovement,
  StockMovementType,
} from "@/features/inventory/lib/stock";
import { formatQuantity } from "@/features/pos/lib/format";
import { formatLocalDateTime } from "@/lib/datetime";
import { cn } from "cn";

interface StockMovementListProps {
  /** Movements, newest first — the screen loads them from the ledger API. */
  movements: readonly StockMovement[];
  /** `true` while the ledger is being read. */
  isLoading?: boolean;
  /** `true` when further pages exist behind a "Load more" control. */
  hasMore?: boolean;
  /** Requests the next page of the ledger. */
  onLoadMore?: () => void;
}

/**
 * Short column label and tone per ledger type.
 *
 * Phase 25 added the three document-driven types: a purchase brings stock in, a
 * sale takes it out and a sale return puts it back. They are read-only here —
 * their quantity belongs to the document that created the row.
 */
const MOVEMENT_TYPE_LABELS: Record<StockMovementType, string> = {
  receive: "Receive",
  remove: "Remove",
  set: "Set count",
  purchase: "Purchase",
  sale: "Sale",
  sale_return: "Sale return",
};

/** Badge wording/colours per movement type. */
function MovementTypeBadge({ type }: { type: StockMovementType }) {
  switch (type) {
    case "receive":
    case "purchase":
    case "sale_return":
      return (
        <Badge
          variant="outline"
          className="border-success/40 bg-success/10 text-success"
        >
          {MOVEMENT_TYPE_LABELS[type]}
        </Badge>
      );
    case "remove":
    case "sale":
      return <Badge variant="destructive">{MOVEMENT_TYPE_LABELS[type]}</Badge>;
    case "set":
      return <Badge variant="secondary">{MOVEMENT_TYPE_LABELS.set}</Badge>;
  }
}

/**
 * Stock movement history (Phase 7, Phase 25).
 *
 * Reads `GET /stock-movements`, one page at a time, so a long ledger never has
 * to be downloaded in full. Memoised on the array reference: an adjustment
 * produces a new array, every other screen update leaves this panel untouched.
 */
export const StockMovementList = memo(function StockMovementList({
  movements,
  isLoading = false,
  hasMore = false,
  onLoadMore,
}: StockMovementListProps) {
  return (
    <section
      aria-label="Stock movements"
      className="overflow-hidden rounded-lg border border-border bg-card"
    >
      <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
        <h2 className="text-sm font-semibold tracking-tight">Stock movements</h2>
        <p className="text-xs text-muted-foreground" aria-live="polite">
          {isLoading ? "Loading…" : "From the stock ledger"}
        </p>
      </div>

      {movements.length === 0 ? (
        <div className="flex flex-col items-center justify-center gap-1.5 px-6 py-10 text-center">
          <Boxes className="size-8 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium">No stock movements yet</p>
          <p className="text-xs text-muted-foreground">
            Every adjustment, purchase, sale and return is recorded here.
          </p>
        </div>
      ) : (
        <>
          <Table className="min-w-[840px]">
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>Type</TableHead>
                <TableHead className="text-right">Change</TableHead>
                <TableHead className="text-right">Stock after</TableHead>
                <TableHead>Note</TableHead>
                <TableHead>Recorded by</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.map((movement) => (
                <MovementRow key={movement.id} movement={movement} />
              ))}
            </TableBody>
          </Table>

          {hasMore && onLoadMore !== undefined && (
            <div className="border-t border-border px-4 py-2.5 text-center">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onLoadMore}
                disabled={isLoading}
              >
                {isLoading ? "Loading…" : "Load more"}
              </Button>
            </div>
          )}
        </>
      )}
    </section>
  );
});

const MovementRow = memo(function MovementRow({
  movement,
}: {
  movement: StockMovement;
}) {
  return (
    <TableRow>
      {/* Server/client may disagree on time zone only — suppress that flash. */}
      <TableCell className="text-muted-foreground">
        <span suppressHydrationWarning>
          {formatLocalDateTime(movement.occurredAt)}
        </span>
      </TableCell>
      <TableCell className="max-w-56">
        <span className="block truncate font-medium" title={movement.productName}>
          {movement.productName}
        </span>
        <span className="block truncate font-mono text-xs text-muted-foreground">
          {movement.sku}
        </span>
      </TableCell>
      <TableCell>
        <MovementTypeBadge type={movement.type} />
      </TableCell>
      <TableCell
        className={cn(
          "text-right font-medium tabular-nums",
          movement.delta > 0 && "text-success",
          movement.delta < 0 && "text-destructive",
          movement.delta === 0 && "text-muted-foreground",
        )}
      >
        {movement.delta > 0 ? "+" : ""}
        {formatQuantity(movement.delta)}
      </TableCell>
      <TableCell className="text-right tabular-nums">
        {movement.stockAfter === null ? "—" : formatQuantity(movement.stockAfter)}
      </TableCell>
      <TableCell className="max-w-44">
        <span
          className="block truncate text-muted-foreground"
          title={movement.note ?? undefined}
        >
          {movement.note ?? "—"}
        </span>
      </TableCell>
      <TableCell className="text-muted-foreground">{movement.recordedBy}</TableCell>
    </TableRow>
  );
});