"use client";

import { useEffect, useState } from "react";
import { Check, ChevronRight, Loader2, Search, UserRound } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { WALK_IN_CUSTOMER_NAME } from "@/features/receipt/lib/receipt";
import {
  selectCartCustomerName,
  selectCustomerId,
  useCartStore,
} from "@/stores/useCartStore";
import {
  selectCustomerMatches,
  useCustomerStore,
} from "@/stores/useCustomerStore";
import type { Customer } from "@/types/customer";
import { cn } from "cn";

/**
 * POS customer selector (Phase 8, Phase 25).
 *
 * Writes an `{ id, name }` snapshot into the cart store (`setCustomer`), so the
 * running sale is associated with the customer without touching lines, totals
 * or any other POS behaviour: the receipt prints the name and the sale payload
 * reads `customerId`/`customerName`. Picking "Walk-in" clears the attachment;
 * `clearCart()` after checkout resets it anyway.
 *
 * Phase 25: the picker now **searches the API** (`GET /customers?search=…`,
 * debounced) instead of filtering a downloaded directory. That matters at the
 * till: the result set is whatever the server matched, and the outstanding
 * balance badge is gone because `CustomerResource` does not compute one.
 *
 * Inactive customers are excluded by the query itself (`status=active`), which
 * is the documented rule. The dialog keeps its own query state, cleared on
 * close, so every pick starts clean.
 */
export function CustomerSelector() {
  const matches = useCustomerStore(selectCustomerMatches);
  const isLoading = useCustomerStore((state) => state.isLoading);
  const search = useCustomerStore((state) => state.search);
  const customerId = useCartStore(selectCustomerId);
  const customerName = useCartStore(selectCartCustomerName);

  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  // Loads the first page when the picker opens, so it is never empty on the
  // first tap. Subsequent typing re-queries the server.
  useEffect(() => {
    if (!open) {
      return;
    }
    const handle = setTimeout(() => {
      void search(query);
    }, query === "" ? 0 : 250);
    return () => clearTimeout(handle);
  }, [open, query, search]);

  const handleOpenChange = (next: boolean) => {
    setOpen(next);
    if (!next) {
      setQuery("");
    }
  };

  const choose = (customer: Customer | null) => {
    useCartStore.getState().setCustomer(customer);
    setOpen(false);
    setQuery("");
  };

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="w-full justify-start"
        onClick={() => setOpen(true)}
      >
        <UserRound aria-hidden="true" />
        <span className="min-w-0 flex-1 truncate text-left">
          {customerName ?? WALK_IN_CUSTOMER_NAME}
        </span>
        <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      </Button>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Select customer</DialogTitle>
            <DialogDescription>
              Active customers only — inactive rows stay hidden at checkout.
            </DialogDescription>
          </DialogHeader>

          <div className="relative">
            <Search
              className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              type="search"
              className="pl-8"
              placeholder="Search name, phone or email…"
              aria-label="Search customers"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              autoFocus
            />
          </div>

          <div className="max-h-[45dvh] space-y-1.5 overflow-y-auto">
            <button
              type="button"
              onClick={() => choose(null)}
              className={cn(
                "flex w-full items-center gap-2 rounded-lg border border-border px-2.5 py-2 text-left transition-colors hover:bg-muted",
                customerId === null && "border-ring bg-muted",
              )}
            >
              <UserRound className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                  {WALK_IN_CUSTOMER_NAME}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                  No account attached to this sale
                </span>
              </span>
              {customerId === null && (
                <Check className="size-4 shrink-0 text-primary" aria-hidden="true" />
              )}
            </button>

            {isLoading ? (
              <p className="flex items-center justify-center gap-2 px-1 py-6 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                Searching…
              </p>
            ) : matches.length === 0 ? (
              <p className="px-1 py-6 text-center text-sm text-muted-foreground">
                No active customers match your search.
              </p>
            ) : (
              matches.map((customer) => (
                <button
                  key={customer.id}
                  type="button"
                  onClick={() => choose(customer)}
                  className={cn(
                    "flex w-full items-center gap-2 rounded-lg border border-border px-2.5 py-2 text-left transition-colors hover:bg-muted",
                    customer.id === customerId && "border-ring bg-muted",
                  )}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                      {customer.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                      {customer.phone ?? "No phone"}
                    </span>
                  </span>
                  {customer.id === customerId && (
                    <Check className="size-4 shrink-0 text-primary" aria-hidden="true" />
                  )}
                </button>
              ))
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
