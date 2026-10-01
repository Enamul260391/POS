import { Badge } from "@/components/ui/badge";
import type { PurchasePaymentStatus } from "@/types/purchase";

/** Visual style per payment status, shared by table, details and dialogs. */
const STATUS_STYLES: Readonly<
  Record<PurchasePaymentStatus, { label: string; className: string }>
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
};

/**
 * Payment status pill of a purchase (Phase 9).
 *
 * Reusable across the purchase table, the purchase details dialog, the
 * create form preview and the supplier history list, so wording and colours
 * always agree — the same idea as `StockBadge` in the products feature.
 */
export function PaymentStatusBadge({ status }: { status: PurchasePaymentStatus }) {
  const style = STATUS_STYLES[status];
  return (
    <Badge variant="outline" className={style.className}>
      {style.label}
    </Badge>
  );
}