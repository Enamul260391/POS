"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, type UseFormRegister } from "react-hook-form";

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
  createSupplierSchema,
  EMPTY_SUPPLIER_VALUES,
  SUPPLIER_FORM_FIELDS,
  toSupplierFormValues,
  type SupplierFormValues,
} from "@/features/suppliers/lib/supplierSchema";
import {
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { selectSuppliers, useSupplierStore } from "@/stores/useSupplierStore";
import type { Supplier } from "@/types/supplier";

/** What the dialog was opened for: a brand new supplier or an existing row. */
export type SupplierFormTarget =
  | { mode: "add" }
  | { mode: "edit"; supplier: Supplier };

/** Inline validation message rendered under a field. */
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

interface TextFieldProps {
  id: "name" | "phone" | "email" | "address";
  label: string;
  register: UseFormRegister<SupplierFormValues>;
  error?: string;
  placeholder?: string;
  type?: "text" | "tel" | "email";
}

/** Text input bound to react-hook-form through `register`. */
function TextField({
  id,
  label,
  register,
  error,
  placeholder,
  type = "text",
}: TextFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        placeholder={placeholder}
        aria-invalid={error !== undefined}
        {...register(id)}
      />
      <FormError message={error} />
    </div>
  );
}

interface SupplierFormProps {
  target: SupplierFormTarget;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Add/Edit supplier dialog (Phase 25).
 *
 * Validation is React Hook Form + Zod: `createSupplierSchema` closes phone
 * uniqueness over the current directory (excluding the row being edited).
 * Saving goes through the API, so a server-side rejection (a `409` for a
 * supplier that already appears on a purchase, a network failure) is reported
 * in the dialog instead of silently doing nothing. Field-level `422` messages
 * are mapped back onto the matching inputs by `applyValidationErrors`.
 * Mounted only while open, so every open starts from fresh state.
 */
export function SupplierForm({ target, onOpenChange }: SupplierFormProps) {
  const suppliers = useSupplierStore(selectSuppliers);
  const editingId = target.mode === "edit" ? target.supplier.id : null;

  const schema = useMemo(
    () => createSupplierSchema(suppliers, editingId),
    [suppliers, editingId],
  );

  const {
    handleSubmit,
    register,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SupplierFormValues>({
    resolver: zodResolver(schema),
    defaultValues:
      target.mode === "edit"
        ? toSupplierFormValues(target.supplier)
        : EMPTY_SUPPLIER_VALUES,
  });

  const save = handleSubmit(async (values) => {
    const store = useSupplierStore.getState();
    try {
      if (target.mode === "add") {
        await store.createSupplier(values);
      } else {
        await store.updateSupplier(target.supplier.id, values);
      }
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, SUPPLIER_FORM_FIELDS);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    }
  });

  const isEdit = target.mode === "edit";

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit supplier" : "Add supplier"}</DialogTitle>
          <DialogDescription>
            {isEdit ? (
              <>Editing {target.supplier.name} · contact details only.</>
            ) : (
              "New directory entry — who we buy from and how to reach them."
            )}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={save} noValidate className="grid gap-4">
          <div className="grid max-h-[55dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2">
            {errors.root !== undefined && (
              <p
                role="alert"
                className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive sm:col-span-2"
              >
                {errors.root.message}
              </p>
            )}
            <TextField
              id="name"
              label="Supplier name"
              register={register}
              error={errors.name?.message}
              placeholder="Metro Beverage Depot"
            />
            <TextField
              id="phone"
              label="Phone"
              type="tel"
              register={register}
              error={errors.phone?.message}
              placeholder="+8801711000111"
            />
            <TextField
              id="email"
              label="Email"
              type="email"
              register={register}
              error={errors.email?.message}
              placeholder="sales@example.com"
            />
            <TextField
              id="address"
              label="Address"
              register={register}
              error={errors.address?.message}
              placeholder="Tejgaon ICD, Dhaka"
            />
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isEdit ? "Save changes" : "Save supplier"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}