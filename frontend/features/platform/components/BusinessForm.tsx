"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, type UseFormReturn, type Path, type UseFormRegister } from "react-hook-form";
import { z } from "zod";

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
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { usePlatformBusinessStore } from "@/stores/usePlatformBusinessStore";
import type { Business, BusinessFormValues, BusinessStatus } from "@/types/platform";
import { BUSINESS_STATUS_OPTIONS, EMPTY_BUSINESS_VALUES, BUSINESS_FORM_FIELDS, DEFAULT_BUSINESS_CURRENCY, DEFAULT_BUSINESS_TIMEZONE, toBusinessFormValues } from "@/types/platform";

/** What the dialog was opened for: a brand new business or an existing one. */
export type BusinessFormTarget =
  | { mode: "add" }
  | { mode: "edit"; business: Business };

/**
 * Timezone validation — IANA timezone identifier pattern (loose).
 *
 * `UTC` is accepted next to the `Area/Location` form: it is a valid timezone
 * identifier that businesses may already carry, and rejecting it would make
 * an otherwise valid business unsaveable.
 */
const TIMEZONE_PATTERN = /^(UTC|[A-Za-z_+\-]+\/[A-Za-z_+\-]+)$/;

/** Currency validation — ISO 4217 three-letter code. */
const CURRENCY_PATTERN = /^[A-Z]{3}$/;

/** Loose phone shape: digits, spaces and dashes with an optional leading `+`. */
const PHONE_PATTERN = /^\+?[0-9\s-]{6,20}$/;

const businessFields = {
  name: z.string().trim().min(1, "Name is required").max(255, "Name is too long"),
  phone: z
    .string()
    .trim()
    .max(20, "Phone is too long")
    .refine((value) => value === "" || PHONE_PATTERN.test(value), "Enter a valid phone number"),
  email: z
    .string()
    .trim()
    .max(100, "Email is too long")
    .refine((value) => value === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value), "Enter a valid email address"),
  address: z.string().trim().max(1000, "Address is too long"),
  logo: z.string().trim().max(255, "Logo URL is too long"),
  currency: z
    .string()
    .trim()
    .refine((value) => value === "" || CURRENCY_PATTERN.test(value), "Enter a valid 3-letter currency code (e.g. USD)"),
  timezone: z
    .string()
    .trim()
    .refine((value) => value === "" || TIMEZONE_PATTERN.test(value), "Enter a valid IANA timezone (e.g. America/New_York)"),
  status: z.enum(["active", "inactive"]),
};

/** Valid shape of the business form. */
const baseBusinessSchema = z.object(businessFields);

interface BusinessFormProps {
  target: BusinessFormTarget;
  /** Called when the business is successfully created/updated. */
  onSubmitSuccess?: (business: import("@/types/platform").Business) => void;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Add/Edit business dialog.
 *
 * Validation is React Hook Form + Zod. The form matches the backend
 * `StoreBusinessRequest` / `UpdateBusinessRequest` contract.
 *
 * A save goes through the API via the store. Laravel's `422` error bag is mapped
 * back onto the offending input, and anything else is shown as a form-level
 * message. Success is reported through `onSubmitSuccess`; the parent decides
 * where to navigate, so a rejection leaves the entered values on screen.
 *
 * Mounted only while open, so every open starts from fresh state.
 */
export function BusinessForm({ target, onOpenChange, onSubmitSuccess }: BusinessFormProps) {
  const isEdit = target.mode === "edit";
  const addBusiness = usePlatformBusinessStore((state) => state.addBusiness);
  const updateBusiness = usePlatformBusinessStore((state) => state.updateBusiness);

  const schema = useMemo(() => baseBusinessSchema, []);

  const form = useForm<BusinessFormValues>({
    resolver: zodResolver(schema),
    defaultValues: isEdit ? toBusinessFormValues(target.business) : EMPTY_BUSINESS_VALUES,
  });

  const { handleSubmit, formState, setError } = form;
  const currentErrors = formState.errors;

  const submit = handleSubmit(async (values) => {
    try {
      let business;
      if (isEdit) {
        business = await updateBusiness(target.business.id, values);
      } else {
        business = await addBusiness(values);
      }
      onSubmitSuccess?.(business);
    } catch (error) {
      applyValidationErrors(error, setError, BUSINESS_FORM_FIELDS);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    }
  });

  return (
    <Dialog open onOpenChange={onOpenChange} disablePointerDismissal>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit Business" : "Create Business"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Editing ${target.business.name} — business details and status.`
              : "New business tenant — name, contact, locale and initial status."}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="grid gap-4">
          {currentErrors.root?.message !== undefined && (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
              {currentErrors.root.message}
            </p>
          )}

          <div className="grid max-h-[70dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
            {/*
              Row layout: the business name takes the full width, then three
              rows of two equal fields (email/phone, address/logo,
              currency/timezone). Each pair lives in its own container that
              spans the whole row and splits it in two, so the fields stay
              equal width on every screen size. Below `sm` the pairs stack.
            */}
            <div className="sm:col-span-2 lg:col-span-3">
              <TextField
                id="name"
                label="Business name"
                register={form.register}
                error={currentErrors.name?.message}
                placeholder="Acme Corporation"
              />
            </div>
            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-3">
              <TextField
                id="email"
                label="Email"
                type="email"
                register={form.register}
                error={currentErrors.email?.message}
                placeholder="contact@acme.example"
              />
              <TextField
                id="phone"
                label="Phone"
                type="tel"
                register={form.register}
                error={currentErrors.phone?.message}
                placeholder="+1 555 0123"
              />
            </div>
            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-3">
              <TextField
                id="address"
                label="Address"
                register={form.register}
                error={currentErrors.address?.message}
                placeholder="123 Main St, City, Country"
              />
              <TextField
                id="logo"
                label="Logo URL"
                type="url"
                register={form.register}
                error={currentErrors.logo?.message}
                placeholder="https://example.com/logo.png"
              />
            </div>
            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-3">
              {isEdit ? (
                <>
                  <TextField
                    id="currency"
                    label="Currency (ISO 4217)"
                    register={form.register}
                    error={currentErrors.currency?.message}
                    placeholder={DEFAULT_BUSINESS_CURRENCY}
                  />
                  <TextField
                    id="timezone"
                    label="Timezone (IANA)"
                    register={form.register}
                    error={currentErrors.timezone?.message}
                    placeholder={DEFAULT_BUSINESS_TIMEZONE}
                  />
                </>
              ) : (
                <>
                  <ReadOnlyField
                    id="currency"
                    label="Currency"
                    value={`${DEFAULT_BUSINESS_CURRENCY} — ৳`}
                    hint="Bangladeshi Taka"
                  />
                  <ReadOnlyField
                    id="timezone"
                    label="Timezone"
                    value={DEFAULT_BUSINESS_TIMEZONE}
                    hint="UTC+06:00"
                  />
                  {/*
                    The rows above are display only, so the values the API
                    receives are carried by these hidden registered fields.
                  */}
                  <input type="hidden" {...form.register("currency")} />
                  <input type="hidden" {...form.register("timezone")} />
                </>
              )}
            </div>
            <SelectField
              name="status"
              label="Status"
              form={form}
              options={BUSINESS_STATUS_OPTIONS}
              error={currentErrors.status?.message}
            />
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={formState.isSubmitting}>
              {isEdit ? "Save changes" : "Create business"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

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

interface ReadOnlyFieldProps {
  id: string;
  label: string;
  /** The text shown to the user — richer than the value in the payload. */
  value: string;
  /** Secondary line under the value (offset, display name, …). */
  hint: string;
}

/**
 * A fixed value the Platform Owner cannot change (yet).
 *
 * The visible field is a read-only input, so the label is properly associated
 * with it and assistive technology announces it as read-only. The value the
 * API receives travels in a hidden registered field instead, so the displayed
 * text can be richer than the payload value.
 */
function ReadOnlyField({ id, label, value, hint }: ReadOnlyFieldProps) {
  return (
    <div className="grid gap-1.5">
      <div className="flex items-center justify-between gap-2">
        <Label htmlFor={id}>{label}</Label>
        <span className="text-xs text-muted-foreground">Read-only</span>
      </div>
      <Input
        id={id}
        value={value}
        readOnly
        aria-describedby={`${id}-hint`}
        className="bg-muted/60 text-muted-foreground"
      />
      <p id={`${id}-hint`} className="text-xs text-muted-foreground">
        {hint}
      </p>
    </div>
  );
}

interface TextFieldProps {
  id: Path<BusinessFormValues>;
  label: string;
  register: UseFormRegister<BusinessFormValues>;
  error?: string;
  placeholder?: string;
  type?: "text" | "tel" | "email" | "url";
}

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
  form: UseFormReturn<BusinessFormValues>;
  options: readonly { value: BusinessStatus; label: string }[];
  error?: string;
}

function SelectField({ name, label, form, options, error }: SelectFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Select
        onValueChange={(value) => form.setValue(name, value as BusinessStatus, { shouldValidate: true })}
        defaultValue={form.watch(name) ?? options[0].value}
      >
        <SelectTrigger id={name} className="w-full" aria-invalid={error !== undefined}>
          <SelectValue placeholder="Select status" />
        </SelectTrigger>
        <SelectContent>
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <FormError message={error} />
    </div>
  );
}