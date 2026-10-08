"use client";

import { useState } from "react";
import { Loader2, TriangleAlert, Building2 } from "lucide-react";

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
import { messageOfApiError } from "@/lib/api/validation";
import { usePlatformBusinessStore } from "@/stores/usePlatformBusinessStore";
import type { Business, BusinessStatus } from "@/types/platform";

interface BusinessStatusDialogProps {
  /** The business whose status is about to be changed. */
  business: Business;
  /** The target status. */
  targetStatus: BusinessStatus;
  /** Whether the dialog is open. */
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Confirm business status change (activate/deactivate).
 *
 * Uses the same `Dialog` shell as other confirmation dialogs. The destructive
 * action is styled as such and the wording names the exact business being
 * affected, so nobody changes status without knowing which.
 */
export function BusinessStatusDialog({ business, targetStatus, open, onOpenChange }: BusinessStatusDialogProps) {
  const setBusinessStatus = usePlatformBusinessStore((state) => state.setBusinessStatus);
  const [isChanging, setIsChanging] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const isActivate = targetStatus === "active";

  const confirm = async () => {
    setFormError(null);

    if (isChanging) {
      return;
    }

    setIsChanging(true);
    try {
      await setBusinessStatus(business.id, targetStatus);
      onOpenChange(false);
    } catch (error) {
      setFormError(messageOfApiError(error, `Could not ${isActivate ? "activate" : "deactivate"} the business.`));
    } finally {
      setIsChanging(false);
    }
  };

  const actionWord = isActivate ? "Activate" : "Deactivate";
  const actionWordLower = actionWord.toLowerCase();

  return (
    <Dialog open={open} disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Building2 className="size-4" aria-hidden="true" />
            {actionWord} Business
          </DialogTitle>
          <DialogDescription>
            Are you sure you want to {actionWordLower} <strong>{business.name}</strong>?
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            {isActivate
              ? "The business will be accessible to its users again."
              : "Users belonging to this business will lose access while the business is inactive."}
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
              <Button type="button" variant="outline" disabled={isChanging} />
            }
          >
            Cancel
          </DialogClose>
          <Button
            type="button"
            variant="destructive"
            onClick={() => void confirm()}
            disabled={isChanging}
          >
            {isChanging ? (
              <>
                <Loader2 className="animate-spin" aria-hidden="true" />
                {actionWord}ing…
              </>
            ) : (
              `${actionWord} business`
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}