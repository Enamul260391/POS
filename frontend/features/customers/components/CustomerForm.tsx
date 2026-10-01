"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import {
  Controller,
  useForm,
  type Control,
  type UseFormRegister,
} from "react-hook-form";

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
} from "@/components/ui/select";
import {
  baseCustomerSchema,
  EMPTY_CUSTOMER_VALUES,
  STATUS_OPTIONS,
  toCustomerFormValues,
  type CustomerFormValues,
} from "@/features/customers/lib/customerSchema";
import { applyValidationErrors, hasValidationErrors, messageOfApiError } from "@/lib/api/validation";
import { useCustomerStore } from "@/stores/useCustomerStore";
import type { Customer } from "@/types/customer";

/** What the dialog was opened for: a brand new customer or an existing row. */
export type CustomerFormTarget =
  | { mode: "add" }
  | { mode: "edit"; customer: Customer };

/** Field names a `422` may address, in the frontend's camelCase spelling. */
const CUSTOMER_FORM_FIELDS = [
  "name",
  "phone",
  "email",
  "address",
  "openingBalance",
  "status",
] as const;

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
  register: UseFormRegister<CustomerFormValues>;
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

interface SelectFieldProps {
  name: "status";
  label: string;
  control: Control<CustomerFormValues>;
  options: readonly { value: string; label: string }[];
  error?: string;
}

/** Select bound to react-hook-form through `Controller`. */
function SelectField({ name, label, control, options, error }: SelectFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Controller
        control={control}
        name={name}
        render={({ field }) => {
          const current = String(field.value);
          const selected = options.find((option) => option.value === current);
          return (
            <Select
              value={selected?.value ?? null}
              onValueChange={(value) => field.onChange(value)}
            >
              <SelectTrigger
                id={name}
                className="w-full"
                aria-invalid={error !== undefined}
              >
                <span>{selected?.label ?? "Select status"}</span>
              </SelectTrigger>
              <SelectContent>
                {options.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          );
        }}
      />
      <FormError message={error} />
    </div>
  );
}

interface CustomerFormProps {
  target: CustomerFormTarget;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Add/Edit customer dialog (Phase 8).
 *
 * Validation is React Hook Form + Zod: `createCustomerSchema` closes phone
 * and email uniqueness over the current directory (excluding the row being
 * edited), so the form can never save a duplicate contact even though data is
 * Mounted only while open, so every open starts from fresh
 * state.
 */
export function CustomerForm({ target, onOpenChange }: CustomerFormProps) {
  const schema = useMemo(() => baseCustomerSchema, []);

  const {
    control,
    handleSubmit,
    register,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<CustomerFormValues>({
    resolver: zodResolver(schema),
    defaultValues:
      target.mode === "edit"
        ? toCustomerFormValues(target.customer)
        : EMPTY_CUSTOMER_VALUES,
  });

  /**
   * Saves through the API and updates the directory cache with the response.
   *
   * Phase 25: a `422` from `StoreCustomerRequest` / `UpdateCustomerRequest` is
   * mapped back onto the offending input (Laravel keys errors by column name —
   * `opening_balance` — so the translation happens in `@/lib/api/validation`),
   * and anything else is shown as a form-level message. The dialog closes only
   * on success, so a rejection leaves the entered values on screen.
   */
  const save = handleSubmit(async (values) => {
    const store = useCustomerStore.getState();
    try {
      if (target.mode === "add") {
        await store.createCustomer(values);
      } else {
        await store.updateCustomer(target.customer.id, values);
      }
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, CUSTOMER_FORM_FIELDS);
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
          <DialogTitle>{isEdit ? "Edit customer" : "Add customer"}</DialogTitle>
          <DialogDescription>
            {isEdit ? (
              <>Editing {target.customer.name} · contact details only.</>
            ) : (
              "New directory entry — name and contact details first."
            )}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={save} noValidate className="grid gap-4">
          <div className="grid max-h-[55dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2">
            <TextField
              id="name"
              label="Customer name"
              register={register}
              error={errors.name?.message}
              placeholder="Rahim Uddin"
            />
            <TextField
              id="phone"
              label="Phone"
              type="tel"
              register={register}
              error={errors.phone?.message}
              placeholder="+8801712345678"
            />
            <TextField
              id="email"
              label="Email"
              type="email"
              register={register}
              error={errors.email?.message}
              placeholder="rahim@example.com"
            />
            <TextField
              id="address"
              label="Address"
              register={register}
              error={errors.address?.message}
              placeholder="Mirpur 10, Dhaka"
            />
            <SelectField
              name="status"
              label="Status"
              control={control}
              options={STATUS_OPTIONS}
              error={errors.status?.message}
            />
            {errors.root?.message !== undefined && (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive sm:col-span-2"
              >
                {errors.root.message}
              </p>
            )}
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isEdit ? "Save changes" : "Save customer"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}