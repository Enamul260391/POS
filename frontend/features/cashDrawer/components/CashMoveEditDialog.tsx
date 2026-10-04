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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  cashMoveEditSchema,
  type CashMoveEditFormValues,
} from "@/features/cashDrawer/lib/cashDrawerSchema";
import { formatMoney } from "@/features/pos/lib/format";
import {
  applyValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { useCashDrawerStore } from "@/stores/useCashDrawerStore";
import type { CashRegisterTransaction } from "@/types/cashDrawer";

interface CashMoveEditDialogProps {
  /** The manual cash in / cash out being amended. */
  transaction: CashRegisterTransaction;
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
 * Amend a manual cash in / cash out.
 *
 * Reuses the `CashMoveDialog` layout and the same three fields the API accepts,
 * so an amendment and a new movement are typed the same way. The form is seeded
 * from the row being edited rather than left blank, so the cashier is correcting
 * a visible value rather than retyping a remembered one.
 *
 * Submit path: `useCashDrawerStore.updateCashMove` →
 * `PATCH /cash-registers/{id}/transactions/{transactionId}`. The store then
 * re-reads the drawer, so the balance shown afterwards is the server's own
 * recomputed aggregate — nothing here adjusts a figure locally.
 *
 * The direction is offered as a `Select` limited to Cash in / Cash out rather
 * than as free text: those are the only two types the API will accept here, so
 * the form cannot express a request that would turn this manual movement into a
 * reference-linked automatic one. The API enforces that again server-side, and
 * refuses the whole row if it is not manual in the first place.
 *
 * As in `CashMoveDialog`, the dialog closes only after the request resolves; a
 * rejection keeps the entered values for a retry, and `isSubmitting` guards both
 * the button and the handler so one amendment cannot be posted twice.
 */
export function CashMoveEditDialog({
  transaction,
  onOpenChange,
}: CashMoveEditDialogProps) {
  const updateCashMove = useCashDrawerStore((state) => state.updateCashMove);
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<CashMoveEditFormValues>({
    resolver: zodResolver(cashMoveEditSchema),
    defaultValues: {
      // The row is only ever opened when it is manual, so its type is one of
      // the two the enum accepts.
      type: transaction.type === "cash_out" ? "cash_out" : "cash_in",
      amount: transaction.amount,
      note: transaction.note ?? "",
    },
  });
  const { register, handleSubmit, setError, setValue, control, formState } = form;
  const { errors, isSubmitting } = formState;
  // `useWatch` (not `form.watch`) so the React Compiler can memoise this
  // component — the same reason `CloseRegisterDialog` and `ExpenseFormDialog`
  // read their live values through the hook rather than the form object.
  const type = useWatch({ control, name: "type" });

  const submit = handleSubmit(async (values) => {
    setFormError(null);

    if (isSubmitting) {
      return;
    }

    try {
      await updateCashMove(transaction.id, {
        type: values.type,
        amount: values.amount,
        note: values.note.trim() || null,
      });
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, ["amount", "note"]);
      setFormError(messageOfApiError(error, "Could not update the movement."));
    }
  });

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit cash movement</DialogTitle>
          <DialogDescription>
            Correct this manual cash movement. Sales and expense movements are
            written by the API and cannot be edited here.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} noValidate className="grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="cash-move-edit-type">Direction</Label>
            <Select
              value={type}
              onValueChange={(value) =>
                setValue("type", value as CashMoveEditFormValues["type"])
              }
              disabled={isSubmitting}
            >
              <SelectTrigger id="cash-move-edit-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="cash_in">Cash in</SelectItem>
                <SelectItem value="cash_out">Cash out</SelectItem>
              </SelectContent>
            </Select>
            <FormError message={errors.type?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="cash-move-edit-amount">Amount (৳)</Label>
            <Input
              id="cash-move-edit-amount"
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
            <Label htmlFor="cash-move-edit-note">Note</Label>
            <Input
              id="cash-move-edit-note"
              placeholder="Reason for this movement…"
              maxLength={2000}
              disabled={isSubmitting}
              aria-invalid={errors.note !== undefined}
              {...register("note")}
            />
            <FormError message={errors.note?.message} />
          </div>

          <p className="text-xs text-muted-foreground">
            Recorded as {type === "cash_out" ? "−" : "+"}
            {formatMoney(transaction.amount)}. Saving recalculates the drawer
            balance from the server&apos;s own ledger.
          </p>

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
                  Saving…
                </>
              ) : (
                "Save changes"
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}