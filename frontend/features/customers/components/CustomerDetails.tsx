"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { formatMoney } from "@/features/pos/lib/format";
import { STATUS_OPTIONS } from "@/features/customers/lib/customerSchema";
import { formatLocalDateTime } from "@/lib/datetime";
import type { Customer } from "@/types/customer";
import { cn } from "cn";

interface CustomerDetailsProps {
  customer: Customer;
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
 * Read-only customer detail view — the "View" action (Phase 8, Phase 25).
 *
 * Shows exactly what `CustomerResource` returns: contact details, the stored
 * opening balance and the timestamps. Phase 25 removed the "total purchases",
 * "outstanding balance", "loyalty points" and "last purchase" rows — the API
 * computes none of them, and inventing zeroes there would misrepresent a real
 * customer's account. Mounted only while open, like the form dialog.
 */
export function CustomerDetails({ customer, onOpenChange }: CustomerDetailsProps) {
  const statusLabel =
    STATUS_OPTIONS.find((option) => option.value === customer.status)?.label
    ?? customer.status;

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{customer.name}</DialogTitle>
          <DialogDescription>Customer details</DialogDescription>
        </DialogHeader>

        <div className="flex items-center justify-between gap-2">
          {customer.status === "active" ? (
            <Badge variant="outline" className="border-success/40 bg-success/10 text-success">
              {statusLabel}
            </Badge>
          ) : (
            <Badge variant="secondary">{statusLabel}</Badge>
          )}
        </div>

        <dl className="divide-y divide-border">
          <DetailRow term="Phone" value={customer.phone ?? "—"} />
          <DetailRow term="Email" value={customer.email ?? "—"} />
          <DetailRow term="Address" value={customer.address ?? "—"} />
          <DetailRow
            term="Opening balance"
            value={formatMoney(customer.openingBalance)}
          />
          <DetailRow term="Customer since" value={formatLocalDateTime(customer.createdAt)} />
          <DetailRow term="Updated" value={formatLocalDateTime(customer.updatedAt)} />
        </dl>

        <div className="flex justify-end">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}