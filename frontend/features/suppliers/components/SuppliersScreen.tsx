"use client";

import { useEffect, useState } from "react";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { SupplierDetails } from "@/features/suppliers/components/SupplierDetails";
import {
  SupplierForm,
  type SupplierFormTarget,
} from "@/features/suppliers/components/SupplierForm";
import { SupplierSearch } from "@/features/suppliers/components/SupplierSearch";
import { SupplierTable } from "@/features/suppliers/components/SupplierTable";
import { selectSuppliers, useSupplierStore } from "@/stores/useSupplierStore";
import type { Supplier } from "@/types/supplier";

/** How long the search box stays quiet before the API is asked. */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * `/suppliers` screen — composition only (Phase 25).
 *
 * Owns the search term and which dialog (if any) is mounted; the directory
 * itself lives in the supplier store, which hydrates from `GET /suppliers`.
 *
 * Phase 25 removed the in-browser `filterSuppliers` pass: the search term now
 * goes to the backend as `?search=`, so the rows on screen are exactly the rows
 * the server matched, and a large directory is never downloaded in full just to
 * be narrowed locally. Typing is debounced and every superseded request is
 * cancelled, so a fast typist cannot have a slow "me" response overwrite a fast
 * "metro" response.
 */
export function SuppliersScreen() {
  const suppliers = useSupplierStore(selectSuppliers);
  const total = useSupplierStore((state) => state.total);
  const isLoading = useSupplierStore((state) => state.isLoading);
  const isLoaded = useSupplierStore((state) => state.isLoaded);
  const error = useSupplierStore((state) => state.error);
  const load = useSupplierStore((state) => state.load);

  const [query, setQuery] = useState("");
  const [formTarget, setFormTarget] = useState<SupplierFormTarget | null>(null);
  const [detailsTarget, setDetailsTarget] = useState<Supplier | null>(null);

  useEffect(() => {
    if (isLoaded) {
      return;
    }
    void load();
  }, [isLoaded, load]);

  useEffect(() => {
    const handle = setTimeout(() => {
      void load({ search: query.trim() });
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(handle);
  }, [query, load]);

  return (
    <section aria-label="Suppliers" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end">
        <Button type="button" onClick={() => setFormTarget({ mode: "add" })}>
          <Plus aria-hidden="true" />
          Add supplier
        </Button>
      </div>

      <SupplierSearch
        query={query}
        onQueryChange={setQuery}
        shownCount={suppliers.length}
        totalCount={total}
        isLoading={isLoading}
      />

      {error !== null && (
        <p role="alert" className="text-xs text-destructive">
          {error}
        </p>
      )}

      <SupplierTable
        suppliers={suppliers}
        onView={setDetailsTarget}
        onEdit={(supplier) => setFormTarget({ mode: "edit", supplier })}
      />

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {formTarget !== null && (
        <SupplierForm
          target={formTarget}
          onOpenChange={(open) => {
            if (!open) {
              setFormTarget(null);
            }
          }}
        />
      )}
      {detailsTarget !== null && (
        <SupplierDetails
          supplier={detailsTarget}
          onOpenChange={(open) => {
            if (!open) {
              setDetailsTarget(null);
            }
          }}
        />
      )}
    </section>
  );
}