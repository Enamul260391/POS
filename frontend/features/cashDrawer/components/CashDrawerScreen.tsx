"use client";

import { useEffect, useState } from "react";
import {
  ArrowDownLeft,
  ArrowUpRight,
  Banknote,
  DoorOpen,
  Loader2,
  LockKeyhole,
  RefreshCw,
  Wallet,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CashDrawerStats } from "@/features/cashDrawer/components/CashDrawerStats";
import { CashMovementList } from "@/features/cashDrawer/components/CashMovementList";
import { CashMoveDialog } from "@/features/cashDrawer/components/CashMoveDialog";
import { CloseRegisterDialog } from "@/features/cashDrawer/components/CloseRegisterDialog";
import { OpenRegisterDialog } from "@/features/cashDrawer/components/OpenRegisterDialog";
import { formatMoney } from "@/features/pos/lib/format";
import { formatLocalDateTime } from "@/lib/datetime";
import {
  selectCashRegister,
  selectCashSummary,
  selectCashTransactions,
  useCashDrawerStore,
} from "@/stores/useCashDrawerStore";
import { cn } from "cn";
import type {
  CashRegister,
  CashRegisterSummary,
  CashRegisterTransaction,
} from "@/types/cashDrawer";

/**
 * `/cash-drawer` composition screen.
 *
 * The screen asks the API whether a register is open and renders one of two
 * states from that answer — it never decides "is the drawer open?" from local
 * state, so a refresh or a fresh tab always reflects what Laravel has stored.
 *
 *   no open register   the empty state with the Open register action
 *   open register      the server's opening balance, expected cash, summary and
 *                      transaction history, plus cash in / cash out / close
 *
 * Data comes from `useCashDrawerStore`, which reads
 * `GET /cash-registers/current` and, when one exists, its summary and
 * transactions. The balances shown here are the API's own aggregates; this
 * screen sums nothing itself.
 */
export function CashDrawerScreen() {
  const register = useCashDrawerStore(selectCashRegister);
  const summary = useCashDrawerStore(selectCashSummary);
  const transactions = useCashDrawerStore(selectCashTransactions);
  const isLoading = useCashDrawerStore((state) => state.isLoading);
  const isLoaded = useCashDrawerStore((state) => state.isLoaded);
  const error = useCashDrawerStore((state) => state.error);
  const load = useCashDrawerStore((state) => state.load);

  const [openOpen, setOpenOpen] = useState(false);
  const [moveKind, setMoveKind] = useState<"cash_in" | "cash_out" | null>(null);
  const [closeOpen, setCloseOpen] = useState(false);

  // One read per visit. The store's own `runId` guard makes a Strict Mode
  // double-mount harmless: the first run is discarded rather than duplicating
  // the transaction list.
  useEffect(() => {
    void load();
  }, [load]);

  if (!isLoaded && isLoading) {
    return (
      <section
        aria-label="Cash drawer"
        className="flex min-w-0 flex-1 flex-col items-center justify-center gap-2 text-muted-foreground"
      >
        <Loader2 className="size-6 animate-spin" aria-hidden="true" />
        <p className="text-sm">Loading the cash drawer…</p>
      </section>
    );
  }

  return (
    <CashDrawerContent
      register={register}
      summary={summary}
      transactions={transactions}
      error={error}
      isLoading={isLoading}
      onRefresh={() => void load()}
      openOpen={openOpen}
      moveKind={moveKind}
      closeOpen={closeOpen}
      setOpenOpen={setOpenOpen}
      setMoveKind={setMoveKind}
      setCloseOpen={setCloseOpen}
    />
  );
}

type DrawerProps = {
  register: CashRegister | null;
  summary: CashRegisterSummary | null;
  transactions: CashRegisterTransaction[];
  error: string | null;
  isLoading: boolean;
  onRefresh: () => void;
  openOpen: boolean;
  moveKind: "cash_in" | "cash_out" | null;
  closeOpen: boolean;
  setOpenOpen: (open: boolean) => void;
  setMoveKind: (kind: "cash_in" | "cash_out" | null) => void;
  setCloseOpen: (open: boolean) => void;
};

function CashDrawerContent({
  register,
  summary,
  transactions,
  error,
  isLoading,
  onRefresh,
  openOpen,
  moveKind,
  closeOpen,
  setOpenOpen,
  setMoveKind,
  setCloseOpen,
}: DrawerProps) {
  if (register === null) {
    return (
      <NoSession
        error={error}
        isLoading={isLoading}
        onRefresh={onRefresh}
        openOpen={openOpen}
        onOpenChange={setOpenOpen}
      />
    );
  }

  const isOpen = register.status === "open";
  // The summary endpoint is the authoritative aggregate; the register resource
  // carries the same balance, so the screen still shows a figure if that one
  // request failed.
  const expected = summary === null ? register.currentBalance : summary.currentBalance;

  return (
    <section
      aria-label="Cash drawer"
      className="flex min-w-0 flex-1 flex-col gap-3"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <div className="flex items-center gap-2">
            <h2 className="font-heading text-lg font-semibold">Cash drawer</h2>
            <Badge variant={isOpen ? "default" : "secondary"}>
              {isOpen ? "Open" : "Closed"}
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground">
            Register #{register.id} · opened by{" "}
            {register.openedByName ?? "Unknown"}
            {register.openedAt === null
              ? ""
              : ` · ${formatLocalDateTime(register.openedAt)}`}
          </p>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={onRefresh}
            disabled={isLoading}
          >
            {isLoading ? (
              <Loader2 className="animate-spin" aria-hidden="true" />
            ) : (
              <RefreshCw aria-hidden="true" />
            )}
            Refresh
          </Button>
          {isOpen ? (
            <>
              <Button
                type="button"
                variant="outline"
                onClick={() => setMoveKind("cash_in")}
              >
                <ArrowDownLeft aria-hidden="true" /> Cash in
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => setMoveKind("cash_out")}
              >
                <ArrowUpRight aria-hidden="true" /> Cash out
              </Button>
              <Button
                type="button"
                variant="destructive"
                onClick={() => setCloseOpen(true)}
              >
                <LockKeyhole aria-hidden="true" /> Close register
              </Button>
            </>
          ) : (
            <Button type="button" onClick={() => setOpenOpen(true)}>
              <DoorOpen aria-hidden="true" /> Open register
            </Button>
          )}
        </div>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button type="button" size="sm" variant="outline" onClick={onRefresh}>
            Try again
          </Button>
        </div>
      )}

      {summary !== null && <CashDrawerStats summary={summary} />}

      <div className="grid gap-2 rounded-lg border border-border bg-card px-3 py-2.5 text-sm sm:grid-cols-3">
        <Fact
          label="Opening balance"
          value={formatMoney(register.openingBalance)}
        />
        <Fact label="Current expected cash" value={formatMoney(expected)} strong />
        <Fact
          label={isOpen ? "Started" : "Closed"}
          value={formatLocalDateTime(
            isOpen
              ? (register.openedAt ?? register.createdAt)
              : (register.closedAt ?? register.updatedAt),
          )}
        />
      </div>

      {!isOpen && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-success/30 bg-success/5 px-3 py-2 text-sm text-success">
          <Banknote className="size-4 shrink-0" aria-hidden="true" />
          <span>
            Register closed with {formatMoney(register.closingBalance ?? 0)}{" "}
            counted · expected {formatMoney(register.expectedBalance ?? 0)} ·
            difference {formatMoney(register.difference ?? 0)}
          </span>
        </div>
      )}

      <CashMovementList transactions={transactions} isLoading={isLoading} />

      {openOpen && (
        <OpenRegisterDialog onOpenChange={(open) => !open && setOpenOpen(false)} />
      )}
      {moveKind !== null && (
        <CashMoveDialog
          kind={moveKind}
          onOpenChange={(open) => !open && setMoveKind(null)}
        />
      )}
      {closeOpen && isOpen && (
        <CloseRegisterDialog
          expected={expected}
          onOpenChange={(open) => !open && setCloseOpen(false)}
        />
      )}
    </section>
  );
}

function Fact({
  label,
  value,
  strong = false,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <div className="grid gap-0.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className={cn("font-medium tabular-nums", strong && "text-primary")}>
        {value}
      </span>
    </div>
  );
}

function NoSession({
  error,
  isLoading,
  onRefresh,
  openOpen,
  onOpenChange,
}: {
  error: string | null;
  isLoading: boolean;
  onRefresh: () => void;
  openOpen: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <section
      aria-label="Cash drawer"
      className="flex min-w-0 flex-1 flex-col gap-3"
    >
      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={onRefresh}
          disabled={isLoading}
        >
          {isLoading ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>
        <Button type="button" onClick={() => onOpenChange(true)}>
          <DoorOpen aria-hidden="true" /> Open register
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button type="button" size="sm" variant="outline" onClick={onRefresh}>
            Try again
          </Button>
        </div>
      )}

      <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border px-6 py-14 text-center">
        <Wallet className="size-9 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No register is currently open</p>
        <p className="max-w-md text-xs text-muted-foreground">
          Open the register with the counted starting cash to begin recording cash
          in, cash out and the cash the API posts for sales.
        </p>
      </div>

      {openOpen && <OpenRegisterDialog onOpenChange={onOpenChange} />}
    </section>
  );
}
