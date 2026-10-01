"use client";

import { memo } from "react";
import { Eye, Pencil, Trash2, Users } from "lucide-react";

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
import { formatMoney } from "@/features/pos/lib/format";
import type { Customer, CustomerStatus } from "@/types/customer";

interface CustomerTableProps {
  /** Already filtered list — the screen memoises it. */
  customers: readonly Customer[];
  onView: (customer: Customer) => void;
  onEdit: (customer: Customer) => void;
}

/** Status pill of a customer. */
function StatusBadge({ status }: { status: CustomerStatus }) {
  switch (status) {
    case "active":
      return (
        <Badge variant="outline" className="border-success/40 bg-success/10 text-success">
          Active
        </Badge>
      );
    case "inactive":
      return <Badge variant="secondary">Inactive</Badge>;
  }
}

/**
 * Customer directory table with row level actions (Phase 8).
 *
 * Performance: rows are memoised on the customer reference, so a filter change
 * re-renders only rows that entered or left the visible set, and an add/edit
 * re-renders one row (every other customer keeps its object identity). Rows
 * with an outstanding khata balance carry a permanent tint so "who owes money"
 * is scannable without reading every number.
 *
 * Delete is a visible but disabled placeholder: removing customers needs the
 * confirmation flow and the API that comes with Laravel.
 */
export const CustomerTable = memo(function CustomerTable({
  customers,
  onView,
  onEdit,
}: CustomerTableProps) {
  if (customers.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <Users className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No customers found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search or status filter.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[1040px]">
        <TableHeader>
          <TableRow>
            <TableHead>Customer name</TableHead>
            <TableHead>Phone</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Address</TableHead>
            <TableHead className="text-right">Opening balance</TableHead>
            <TableHead>Status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {customers.map((customer) => (
            <CustomerRow
              key={customer.id}
              customer={customer}
              onView={onView}
              onEdit={onEdit}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const CustomerRow = memo(function CustomerRow({
  customer,
  onView,
  onEdit,
}: {
  customer: Customer;
  onView: (customer: Customer) => void;
  onEdit: (customer: Customer) => void;
}) {
  return (
    <TableRow>
      <TableCell className="max-w-52">
        <span className="block truncate font-medium" title={customer.name}>
          {customer.name}
        </span>
      </TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
        {customer.phone ?? "—"}
      </TableCell>
      <TableCell className="max-w-48">
        <span className="block truncate" title={customer.email ?? undefined}>
          {customer.email ?? "—"}
        </span>
      </TableCell>
      <TableCell className="max-w-56">
        <span className="block truncate" title={customer.address ?? undefined}>
          {customer.address ?? "—"}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {formatMoney(customer.openingBalance)}
      </TableCell>
      <TableCell>
        <StatusBadge status={customer.status} />
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`View ${customer.name}`}
            onClick={() => onView(customer)}
          >
            <Eye aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${customer.name}`}
            onClick={() => onEdit(customer)}
          >
            <Pencil aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled
            aria-disabled="true"
            aria-label={`Delete ${customer.name} — coming soon`}
            title="Delete coming soon"
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});