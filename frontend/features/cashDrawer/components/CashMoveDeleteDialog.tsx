"use client";

import { useState } from "react";
import { Loader2, TriangleAlert } from "lucide-react";

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
import { cashTransactionLabel } from "@/features/cashDrawer/lib/cashDrawer";
import { formatMoney } from "@/features/pos/lib/format";
import { messageOfApiError } from "@/lib/api/validation";
import { useCashDrawerStore } from "@/stores/useCashDrawerStore";
import type { CashRegisterTransaction } from "@/types/cashDrawer";

interface CashMoveDeleteDialogProps {
  /** The manual cash in / cash out about to be removed. */
  transaction: CashRegisterTransaction;
  onOpenChange: (open: boolean) => void;
}

/**
 * Confirm removal of a manual cash in / cash out.
 *
 * Uses the same `Dialog` shell and footer convention as the other drawer
 * dialogs — there is no separate confirmation component in the design system, and
 * inventing one for a single action would be a bigger change than the feature
 * needs. The destructive action is styled as such and the wording names the
 * exact movement being removed, so nobody deletes "a transaction" without knowing
 * which.
 *
 * Deleting changes the drawer's balance, so the copy says so plainly and the
 * submit path (`useCashDrawerStore.deleteCashMove` → `DELETE
 * /cash-registers/{id}/transactions/{transactionId}`) re-reads the register, its
 * summary and its history afterwards. The store owns refresh and error state
 * exactly as it does for every other drawer mutation, so a failure surfaces
 * through the same banner rather than a bespoke toast.
 *
 * The dialog only ever opens for a row {@link isManualCashMovement} accepted:
 * the button that opens it is rendered only under the same condition.
 */
export function CashMoveDeleteDialog({
  transaction,
  onOpenChange,
}: CashMoveDeleteDialogProps) {
  const deleteCashMove = useCashDrawerStore((state) => state.deleteCashMove);
  const [isDeleting, setIsDeleting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const confirm = async () => {
    setFormError(null);

    if (isDeleting) {
      return;
    }

    setIsDeleting(true);
    try {
      await deleteCashMove(transaction.id);
      onOpenChange(false);
    } catch (error) {
      setFormError(messageOfApiError(error, "Could not delete the movement."));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Delete cash movement</DialogTitle>
          <DialogDescription>
            This removes the {cashTransactionLabel(transaction.type)} of{" "}
            {formatMoney(transaction.amount)} from the drawer&apos;s history. The
            expected cash balance is recalculated by the API afterwards.
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            This cannot be undone. Sales and expense movements are not deletable
            and are never offered here.
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
              <Button type="button" variant="outline" disabled={isDeleting} />
            }
          >
            Cancel
          </DialogClose>
          <Button
            type="button"
            variant="destructive"
            onClick={() => void confirm()}
            disabled={isDeleting}
          >
            {isDeleting ? (
              <>
                <Loader2 className="animate-spin" aria-hidden="true" />
                Deleting…
              </>
            ) : (
              "Delete movement"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}