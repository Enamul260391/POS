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
  openRegisterSchema,
  type OpenRegisterFormValues,
} from "@/features/cashDrawer/lib/cashDrawerSchema";
import {
  applyValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { useCashDrawerStore } from "@/stores/useCashDrawerStore";

interface OpenRegisterDialogProps {
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
 * Open register dialog.
 *
 * Submit path: `useCashDrawerStore.openRegister` → `POST /cash-registers/open`
 * (`OpenCashRegisterRequest`). The store re-reads the register, its summary and
 * its history from the API on success, so the screen switches to the open state
 * from persisted data rather than from a local guess.
 *
 * The dialog closes only after the request resolves. A rejection — including the
 * backend's "A cash register is already open for this user." — leaves it open
 * with the entered values intact so it can be corrected or retried; nothing ever
 * reports a success the API did not confirm.
 *
 * `isSubmitting` is React Hook Form's in-flight flag and the submit button is
 * disabled on it, with a matching guard inside the handler, so a double click
 * cannot open two registers.
 */
export function OpenRegisterDialog({ onOpenChange }: OpenRegisterDialogProps) {
  const openRegister = useCashDrawerStore((state) => state.openRegister);
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<OpenRegisterFormValues>({
    resolver: zodResolver(openRegisterSchema),
    defaultValues: { openingBalance: Number.NaN, notes: "" },
  });
  const { register, handleSubmit, setError, formState } = form;
  const { errors, isSubmitting } = formState;

  const submit = handleSubmit(async (values) => {
    setFormError(null);

    if (isSubmitting) {
      return;
    }

    try {
      await openRegister({
        openingBalance: values.openingBalance,
        notes: values.notes.trim() || null,
      });
      onOpenChange(false);
    } catch (error) {
      // Field-level `422` messages land on their inputs; anything else (a
      // `409` "already open", a `403`, a timeout) becomes a single banner.
      applyValidationErrors(error, setError, ["openingBalance", "notes"]);
      setFormError(messageOfApiError(error, "Could not open the register."));
    }
  });

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Open register</DialogTitle>
          <DialogDescription>
            Count the opening float and enter it below. The register and its
            opening entry are recorded immediately.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} noValidate className="grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="opening-balance">Opening balance (৳)</Label>
            <Input
              id="opening-balance"
              type="number"
              inputMode="decimal"
              min={0}
              step="0.01"
              placeholder="0.00"
              disabled={isSubmitting}
              aria-invalid={errors.openingBalance !== undefined}
              {...register("openingBalance", { valueAsNumber: true })}
            />
            <FormError message={errors.openingBalance?.message} />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="open-note">Note</Label>
            <Input
              id="open-note"
              placeholder="Morning shift — counter 1…"
              maxLength={2000}
              disabled={isSubmitting}
              aria-invalid={errors.notes !== undefined}
              {...register("notes")}
            />
            <FormError message={errors.notes?.message} />
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
                  Opening…
                </>
              ) : (
                "Open register"
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
