"use client";

import { memo } from "react";
import { Eye, Handshake, Pencil } from "lucide-react";

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
import { isSelectable } from "@/features/suppliers/lib/suppliers";
import type { Supplier } from "@/types/supplier";
import { cn } from "cn";

interface SupplierTableProps {
  /** Already filtered list — the screen memoises it. */
  suppliers: readonly Supplier[];
  onView: (supplier: Supplier) => void;
  onEdit: (supplier: Supplier) => void;
}

/**
 * Supplier directory table with row level actions (Phase 25).
 *
 * Performance: rows are memoised on the supplier reference, so a filter change
 * re-renders only rows that entered or left the visible set, and an add/edit
 * re-renders one row. Inactive suppliers are dimmed, because the purchase form
 * refuses to select them.
 *
 * The columns are exactly the fields `SupplierResource` returns. Phase 9's
 * "Contact person" and "Outstanding balance" columns are gone: the `suppliers`
 * table has no `contact_person`, and it has no aggregate "amount owed" figure —
 * `openingBalance` is a stored starting figure, so showing it under a heading
 * that reads "outstanding" would misreport the books.
 */
export const SupplierTable = memo(function SupplierTable({
  suppliers,
  onView,
  onEdit,
}: SupplierTableProps) {
  if (suppliers.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <Handshake className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No suppliers found</p>
        <p className="text-xs text-muted-foreground">Try a different search.</p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[820px]">
        <TableHeader>
          <TableRow>
            <TableHead>Supplier</TableHead>
            <TableHead>Phone</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Address</TableHead>
            <TableHead className="text-right">Opening balance</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {suppliers.map((supplier) => (
            <SupplierRow
              key={supplier.id}
              supplier={supplier}
              onView={onView}
              onEdit={onEdit}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
});

const SupplierRow = memo(function SupplierRow({
  supplier,
  onView,
  onEdit,
}: {
  supplier: Supplier;
  onView: (supplier: Supplier) => void;
  onEdit: (supplier: Supplier) => void;
}) {
  const active = isSelectable(supplier);

  return (
    <TableRow className={cn(!active && "opacity-60")}>
      <TableCell className="max-w-56">
        <span className="block truncate font-medium" title={supplier.name}>
          {supplier.name}
        </span>
        {supplier.status === "inactive" && (
          <span className="block truncate text-xs text-muted-foreground">
            Inactive — not selectable on a purchase
          </span>
        )}
      </TableCell>
      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
        {supplier.phone ?? "—"}
      </TableCell>
      <TableCell className="max-w-48">
        <span className="block truncate" title={supplier.email ?? undefined}>
          {supplier.email ?? "—"}
        </span>
      </TableCell>
      <TableCell className="max-w-56">
        <span className="block truncate" title={supplier.address ?? undefined}>
          {supplier.address ?? "—"}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {formatMoney(supplier.openingBalance)}
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`View ${supplier.name}`}
            onClick={() => onView(supplier)}
          >
            <Eye aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Edit ${supplier.name}`}
            onClick={() => onEdit(supplier)}
          >
            <Pencil aria-hidden="true" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
});