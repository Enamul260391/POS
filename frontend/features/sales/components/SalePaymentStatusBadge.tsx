import { Badge } from "@/components/ui/badge";
import type { SalePaymentStatus } from "@/types/sale";

/** Visual style per payment status, shared by table, details and dialogs. */
const STATUS_STYLES: Readonly<
  Record<SalePaymentStatus, { label: string; className: string }>
> = {
  paid: {
    label: "Paid",
    className: "border-success/40 bg-success/10 text-success",
  },
  partial: {
    label: "Partial",
    className: "border-warning/40 bg-warning/10 text-warning",
  },
  unpaid: {
    label: "Unpaid",
    className: "border-destructive/40 bg-destructive/10 text-destructive",
  },
  refunded: {
    label: "Refunded",
    className: "border-border bg-muted text-muted-foreground",
  },
};

/**
 * Payment status pill of a sale (Phase 10).
 *
 * Reusable across the sales table, the invoice details screen and the return
 * dialog, so wording and colours always agree — the same idea as
 * `PaymentStatusBadge` in the purchases feature.
 */
export function SalePaymentStatusBadge({ status }: { status: SalePaymentStatus }) {
  const style = STATUS_STYLES[status];
  return (
    <Badge variant="outline" className={style.className}>
      {style.label}
    </Badge>
  );
}
