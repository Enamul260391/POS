"use client";

import { useEffect, useMemo, useState } from "react";
import { Loader2, Plus, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { CustomerDetails } from "@/features/customers/components/CustomerDetails";
import {
  CustomerForm,
  type CustomerFormTarget,
} from "@/features/customers/components/CustomerForm";
import { CustomerSearch } from "@/features/customers/components/CustomerSearch";
import { CustomerTable } from "@/features/customers/components/CustomerTable";
import { filterCustomers } from "@/features/customers/lib/customers";
import { selectCustomers, useCustomerStore } from "@/stores/useCustomerStore";
import type { Customer, CustomerStatus } from "@/types/customer";

/**
 * `/customers` screen — composition only (Phase 8, Phase 25).
 *
 * Owns the filter state and which dialog (if any) is mounted. The directory
 * comes from `useCustomerStore`, hydrated from `GET /customers`; `mock/
 * customers.json` is no longer referenced.
 *
 * Phase 25 kept the interaction and added only what an API-backed screen needs:
 * the search term is sent to the backend (debounced) instead of filtering a
 * downloaded array, and the screen gained a loading state, an error state with
 * a retry, and server-side pagination.
 */
export function CustomersScreen() {
  const customers = useCustomerStore(selectCustomers);
  const isLoading = useCustomerStore((state) => state.isLoading);
  const isLoaded = useCustomerStore((state) => state.isLoaded);
  const error = useCustomerStore((state) => state.error);
  const load = useCustomerStore((state) => state.load);

  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<"all" | CustomerStatus>("all");
  const [formTarget, setFormTarget] = useState<CustomerFormTarget | null>(null);
  const [detailsTarget, setDetailsTarget] = useState<Customer | null>(null);

  // One load per filter change. The term is debounced so typing does not fire a
  // request per keystroke; the status filter is applied in SQL too, so it is
  // sent rather than filtered here.
  useEffect(() => {
    const handle = setTimeout(() => {
      void load({ search: query, status: status === "all" ? undefined : status }, 1);
    }, 300);
    return () => clearTimeout(handle);
  }, [load, query, status]);

  const visibleCustomers = useMemo(
    () => filterCustomers(customers, { status }),
    [customers, status],
  );

  return (
    <section aria-label="Customers" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            void load({ search: query, status: status === "all" ? undefined : status }, 1);
          }}
          disabled={isLoading}
        >
          {isLoading ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>
        <Button
          type="button"
          onClick={() => setFormTarget({ mode: "add" })}
          disabled={!isLoaded}
        >
          <Plus aria-hidden="true" />
          Add customer
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => {
              void load({ search: query, status: status === "all" ? undefined : status }, 1);
            }}
          >
            Try again
          </Button>
        </div>
      )}

      <CustomerSearch
        query={query}
        onQueryChange={setQuery}
        status={status}
        onStatusChange={setStatus}
        shownCount={visibleCustomers.length}
        isLoading={isLoading}
      />

      <CustomerTable
        customers={visibleCustomers}
        onView={setDetailsTarget}
        onEdit={(customer) => setFormTarget({ mode: "edit", customer })}
      />

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {formTarget !== null && (
        <CustomerForm
          target={formTarget}
          onOpenChange={(open) => {
            if (!open) {
              setFormTarget(null);
            }
          }}
        />
      )}
      {detailsTarget !== null && (
        <CustomerDetails
          customer={detailsTarget}
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