"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  closeRegisterSchema,
  type CloseRegisterFormValues,
} from "@/features/cashDrawer/lib/cashDrawerSchema";
import { formatMoney } from "@/features/pos/lib/format";
import {
  applyValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { useCashDrawerStore } from "@/stores/useCashDrawerStore";
import { cn } from "cn";

interface CloseRegisterDialogProps {
  /**
   * Cash the API expects in the drawer, i.e. `CashRegisterService::summary()`'s
   * `current_balance` — never a figure summed on the client.
   */
  expected: number;
  onOpenChange: (open: boolean) => void;
}

function FormError({ message }: { message?: string }) {
  if (message === undefined) {
    return null;
  }
  return (
    <p className="text-xs text-destructive" role="alert">
      {message}
    </p>
  );
}

/**
 * Close register dialog.
 *
 * `expected` is the server's own figure, so the cashier counts against the same
 * number the API will store. The `Difference` row is labelled a *preview*: it is
 * a convenience for the counter, while the authoritative `expected_balance` and
 * `difference` are computed and persisted by `CashRegisterService::close()` and
 * read back afterwards — nothing here writes them.
 *
 * Submit path: `useCashDrawerStore.closeRegister` →
 * `POST /cash-registers/{id}/close` (`CloseCashRegisterRequest`: a counted
 * `closing_balance` plus an optional note). The store then re-reads the drawer,
 * which the API now reports as closed, so the screen shows the
 * no-active-register state.
 *
 * Safety: the dialog closes only after the request resolves, and a rejection — a
 * `409` "Cash register is already closed." from a stale screen, a validation
 * error, a network failure — leaves it open with the counted amount intact for a
 * retry. The drawer is never marked closed locally. `isSubmitting` guards both
 * the button and the handler, so a double click cannot close twice.
 */
export function CloseRegisterDialog({
  expected,
  onOpenChange,
}: CloseRegisterDialogProps) {
  const closeRegister = useCashDrawerStore((state) => state.closeRegister);
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<CloseRegisterFormValues>({
    resolver: zodResolver(closeRegisterSchema),
    defaultValues: { closingBalance: Number.NaN, notes: "" },
  });
  const { register, handleSubmit, setError, formState, control } = form;
  const { errors, isSubmitting } = formState;
  // `useWatch` (not `form.watch`) so the React Compiler can memoise the
  // component, matching `SalePaymentDialog`.
  const countedRaw = useWatch({ control, name: "closingBalance" });
  const counted = Number.isFinite(countedRaw) ? countedRaw : null;
  // A preview of what the cashier is about to submit — the API recomputes and
  // stores the real variance itself.
  const preview =
    counted === null ? null : Math.round((counted - expected) * 100) / 100;

  const submit = handleSubmit(async (values) => {
    setFormError(null);

    if (isSubmitting) {
      return;
    }

    try {
      await closeRegister({
        closingBalance: values.closingBalance,
        notes: values.notes.trim() || null,
      });
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, ["closingBalance", "notes"]);
      setFormError(messageOfApiError(error, "Could not close the register."));
    }
  });

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Close register</DialogTitle>
          <DialogDescription>
            Count the cash in the drawer and enter it below. Expected{" "}
            {formatMoney(expected)}.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} noValidate className="grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="closing-balance">Counted cash (৳)</Label>
            <Input
              id="closing-balance"
              type="number"
              inputMode="decimal"
              min={0}
              step="0.01"
              placeholder="0.00"
              disabled={isSubmitting}
              aria-invalid={errors.closingBalance !== undefined}
              {...register("closingBalance", { valueAsNumber: true })}
            />
            <FormError message={errors.closingBalance?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="close-note">Note</Label>
            <Input
              id="close-note"
              placeholder="Handover note…"
              maxLength={2000}
              disabled={isSubmitting}
              aria-invalid={errors.notes !== undefined}
              {...register("notes")}
            />
            <FormError message={errors.notes?.message} />
          </div>

          <div className="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm">
            <span className="text-muted-foreground">Difference (preview)</span>
            <span
              className={cn(
                "font-medium tabular-nums",
                preview !== null && preview !== 0 && "text-destructive",
                preview === 0 && "text-success",
              )}
            >
              {preview === null
                ? "—"
                : `${preview > 0 ? "+" : ""}${formatMoney(preview)}`}
            </span>
          </div>

          {formError !== null && (
            <p className="text-xs text-destructive" role="alert">
              {formError}
            </p>
          )}

          <DialogFooter>
            <DialogClose
              render={
                <Button type="button" variant="outline" disabled={isSubmitting} />
              }
            >
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? (
                <>
                  <Loader2 className="animate-spin" aria-hidden="true" />
                  Closing…
                </>
              ) : (
                "Close register"
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
