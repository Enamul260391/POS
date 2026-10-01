"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
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
  cashMoveSchema,
  type CashMoveFormValues,
  EMPTY_CASH_MOVE_VALUES,
} from "@/features/cashDrawer/lib/cashDrawerSchema";
import {
  applyValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { useCashDrawerStore } from "@/stores/useCashDrawerStore";

interface CashMoveDialogProps {
  kind: "cash_in" | "cash_out";
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
 * Cash in / cash out dialog — the drawer's manual adjustment.
 *
 * One component serves both directions; only the title, the copy and the `type`
 * sent to the API branch on `kind`, which the backend restricts to exactly
 * `cash_in` and `cash_out` (`CashRegisterTransaction::MANUAL_TYPES`).
 *
 * Submit path: `useCashDrawerStore.recordCashMove` →
 * `POST /cash-registers/{id}/transactions`. The store re-reads the register, its
 * summary and its history afterwards, so the balance shown after an adjustment
 * is the server's recomputed figure, never one adjusted by hand here.
 *
 * This is **not** how a sale or a Record Payment reaches the drawer: the API
 * posts those rows itself inside their own transactions, so writing one here
 * would count the same cash twice.
 *
 * The dialog closes only after the request resolves; a rejection (including the
 * backend's "Cash register is already closed." on a stale screen) keeps the
 * entered amount for a retry. `isSubmitting` guards the button and the handler,
 * so one adjustment cannot be posted twice.
 */
export function CashMoveDialog({ kind, onOpenChange }: CashMoveDialogProps) {
  const recordCashMove = useCashDrawerStore((state) => state.recordCashMove);
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<CashMoveFormValues>({
    resolver: zodResolver(cashMoveSchema),
    defaultValues: EMPTY_CASH_MOVE_VALUES,
  });
  const { register, handleSubmit, setError, formState } = form;
  const { errors, isSubmitting } = formState;
  const isIn = kind === "cash_in";

  const submit = handleSubmit(async (values) => {
    setFormError(null);

    if (isSubmitting) {
      return;
    }

    try {
      await recordCashMove({
        type: kind,
        amount: values.amount,
        note: values.note.trim() || null,
      });
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, ["amount", "note"]);
      setFormError(messageOfApiError(error, "Could not record the movement."));
    }
  });

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{isIn ? "Cash in" : "Cash out"}</DialogTitle>
          <DialogDescription>
            {isIn
              ? "Add cash to the drawer, e.g. change fund from the safe."
              : "Remove cash from the drawer, e.g. a supplier paid in cash."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} noValidate className="grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="cash-move-amount">Amount (৳)</Label>
            <Input
              id="cash-move-amount"
              type="number"
              inputMode="decimal"
              min={0}
              step="0.01"
              placeholder="0.00"
              disabled={isSubmitting}
              aria-invalid={errors.amount !== undefined}
              {...register("amount", { valueAsNumber: true })}
            />
            <FormError message={errors.amount?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="cash-move-note">Note</Label>
            <Input
              id="cash-move-note"
              placeholder="Reason for this movement…"
              maxLength={2000}
              disabled={isSubmitting}
              aria-invalid={errors.note !== undefined}
              {...register("note")}
            />
            <FormError message={errors.note?.message} />
          </div>

          {formError !== null && (
            <p className="text-xs text-destructive" role="alert">
              {formError}
            </p>
          )}

          <DialogFooter>
            <DialogClose
              render={<Button type="button" variant="outline" disabled={isSubmitting} />}
            >
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? (
                <>
                  <Loader2 className="animate-spin" aria-hidden="true" />
                  Saving…
                </>
              ) : isIn ? (
                "Add cash"
              ) : (
                "Remove cash"
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
