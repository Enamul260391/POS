import { Badge } from "@/components/ui/badge";
import { SALE_STATUS_LABELS } from "@/features/sales/lib/sales";
import type { SaleStatus } from "@/types/sale";

/** Visual style per lifecycle status, shared by the invoice header group. */
const STATUS_STYLES: Readonly<Record<SaleStatus, string>> = {
  draft: "border-warning/40 bg-warning/10 text-warning",
  completed: "border-success/40 bg-success/10 text-success",
  returned: "border-warning/40 bg-warning/10 text-warning",
  void: "border-destructive/40 bg-destructive/10 text-destructive",
};

/**
 * Lifecycle status pill of a sale (Phase 10).
 *
 * Shown next to the invoice number on `/sales/[id]`, so a parked or cancelled
 * invoice is never mistaken for a completed one.
 */
export function SaleStatusBadge({ status }: { status: SaleStatus }) {
  return (
    <Badge variant="outline" className={STATUS_STYLES[status]}>
      {SALE_STATUS_LABELS[status]}
    </Badge>
  );
}
