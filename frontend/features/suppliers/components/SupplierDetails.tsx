"use client";

import { useMemo } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PaymentStatusBadge } from "@/features/purchases/components/PaymentStatusBadge";
import { formatMoney } from "@/features/pos/lib/format";
import { isSelectable } from "@/features/suppliers/lib/suppliers";
import { selectPurchases, usePurchaseStore } from "@/stores/usePurchaseStore";
import { formatLocalDateTime } from "@/lib/datetime";
import type { Supplier } from "@/types/supplier";
import { cn } from "cn";

interface SupplierDetailsProps {
  supplier: Supplier;
  onOpenChange: (open: boolean) => void;
}

/** Read-only `label … value` row of the detail list. */
function DetailRow({
  term,
  value,
  valueClassName,
}: {
  term: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1.5">
      <dt className="shrink-0 text-sm text-muted-foreground">{term}</dt>
      <dd className={cn("text-right text-sm font-medium break-words", valueClassName)}>
        {value}
      </dd>
    </div>
  );
}

/**
 * Read-only supplier detail view — the "View" action (Phase 9).
 *
 * Shows the full contact information and outstanding balance, plus the
 * purchase relationship this supplier has: order count, lifetime value and
 * the five most recent orders (the store keeps newest first). That history is
 * read-only here — creating orders happens on `/purchases`. Mounted only
 * while open, like the form dialog.
 */
export function SupplierDetails({ supplier, onOpenChange }: SupplierDetailsProps) {
  const purchases = usePurchaseStore(selectPurchases);

  const history = useMemo(
    () => purchases.filter((purchase) => purchase.supplierId === supplier.id),
    [purchases, supplier.id],
  );
  const totalValue = useMemo(
    () => history.reduce((sum, purchase) => sum + purchase.grandTotal, 0),
    [history],
  );
  const recent = history.slice(0, 5);
  const active = isSelectable(supplier);

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{supplier.name}</DialogTitle>
          <DialogDescription>Supplier details</DialogDescription>
        </DialogHeader>

        {!active && <Badge variant="secondary">Inactive</Badge>}

        <div>
          <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            Contact information
          </h3>
          <dl className="divide-y divide-border">
            <DetailRow term="Phone" value={supplier.phone ?? "—"} />
            <DetailRow term="Email" value={supplier.email ?? "—"} />
            <DetailRow term="Address" value={supplier.address ?? "—"} />
            <DetailRow
              term="Opening balance"
              value={formatMoney(supplier.openingBalance)}
            />
            <DetailRow term="Supplier since" value={formatLocalDateTime(supplier.createdAt)} />
            <DetailRow term="Updated" value={formatLocalDateTime(supplier.updatedAt)} />
          </dl>
        </div>

        <div>
          <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            Purchase history
          </h3>
          <dl className="mb-2 divide-y divide-border">
            <DetailRow term="Orders" value={String(history.length)} />
            <DetailRow term="Total ordered" value={formatMoney(totalValue)} />
          </dl>
          {recent.length === 0 ? (
            <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
              No purchases recorded yet.
            </p>
          ) : (
            <ul className="space-y-1.5">
              {recent.map((purchase) => (
                <li
                  key={purchase.id}
                  className="flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5 text-sm"
                >
                  <span className="font-mono text-xs">{purchase.referenceNumber}</span>
                  <span
                    className="ml-auto text-xs text-muted-foreground"
                    suppressHydrationWarning
                  >
                    {formatLocalDateTime(purchase.purchasedAt)}
                  </span>
                  <span className="font-medium tabular-nums">
                    {formatMoney(purchase.grandTotal)}
                  </span>
                  <PaymentStatusBadge status={purchase.paymentStatus} />
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="flex justify-end">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}