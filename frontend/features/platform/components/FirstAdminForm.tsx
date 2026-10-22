"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, type UseFormRegister } from "react-hook-form";
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
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { usePlatformBusinessStore } from "@/stores/usePlatformBusinessStore";
import type { Business, BusinessAdminFormValues } from "@/types/platform";

interface FirstAdminFormProps {
  /** The business for which the first admin is being created. */
  business: Business;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
  /** Called when the first admin is successfully created. */
  onSuccess: (admin: import("@/types/platform").BusinessAdminResponse) => void;
}

const adminFields = {
  name: z.string().trim().min(1, "Name is required").max(120, "Name is too long"),
  email: z
    .string()
    .trim()
    .min(1, "Email is required")
    .email("Enter a valid email address")
    .max(254, "Email is too long"),
  phone: z
    .string()
    .trim()
    .max(20, "Phone is too long")
    .refine((value) => value === "" || /^\+?[0-9\s-]{6,20}$/.test(value), "Enter a valid phone number"),
  branch: z.string().trim().max(120, "Branch is too long"),
  notes: z.string().trim().max(500, "Notes are too long"),
  password: z.string().min(8, "Password must be at least 8 characters"),
  passwordConfirmation: z.string().min(1, "Please confirm your password"),
};

const baseAdminSchema = z.object(adminFields).refine((data) => data.password === data.passwordConfirmation, {
  message: "Passwords do not match",
  path: ["passwordConfirmation"],
});

export function FirstAdminForm({ business, onOpenChange, onSuccess }: FirstAdminFormProps) {
  const provisionFirstAdmin = usePlatformBusinessStore((state) => state.provisionFirstAdmin);

  const schema = useMemo(() => baseAdminSchema, []);

  const form = useForm<BusinessAdminFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      email: "",
      phone: "",
      branch: "",
      notes: "",
      password: "",
      passwordConfirmation: "",
    },
  });

  const { handleSubmit, formState, setError } = form;
  const currentErrors = formState.errors;

  const submit = handleSubmit(async (values) => {
    try {
      const admin = await provisionFirstAdmin(business.id, values);
      onSuccess?.(admin);
    } catch (error) {
      applyValidationErrors(error, setError, ADMIN_FORM_FIELDS);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    }
  });

  return (
    <Dialog open onOpenChange={onOpenChange} disablePointerDismissal>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Create First Business Admin</DialogTitle>
          <DialogDescription>
            Provision the initial administrator for <strong>{business.name}</strong>.
            This account will have full administrative access to this business.
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

          <div className="grid max-h-[70dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2">
            <TextField
              id="name"
              label="Full name"
              register={form.register}
              error={currentErrors.name?.message}
              placeholder="John Doe"
            />
            <TextField
              id="email"
              label="Email"
              type="email"
              register={form.register}
              error={currentErrors.email?.message}
              placeholder="admin@business.example"
            />
            <TextField
              id="phone"
              label="Phone"
              type="tel"
              register={form.register}
              error={currentErrors.phone?.message}
              placeholder="+1 555 0123"
            />
            <TextField
              id="branch"
              label="Branch"
              register={form.register}
              error={currentErrors.branch?.message}
              placeholder="Main Branch"
            />
            <TextField
              id="notes"
              label="Notes"
              register={form.register}
              error={currentErrors.notes?.message}
              placeholder="Internal notes (optional)"
            />
            <TextField
              id="password"
              label="Password"
              type="password"
              register={form.register}
              error={currentErrors.password?.message}
              placeholder="••••••••"
              autoComplete="new-password"
            />
            <TextField
              id="passwordConfirmation"
              label="Confirm password"
              type="password"
              register={form.register}
              error={currentErrors.passwordConfirmation?.message}
              placeholder="••••••••"
              autoComplete="new-password"
            />
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={formState.isSubmitting}>
              {formState.isSubmitting ? "Creating admin…" : "Create admin"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Field names a `422` may address, in the frontend's camelCase spelling. */
export const ADMIN_FORM_FIELDS = [
  "name",
  "email",
  "phone",
  "branch",
  "notes",
  "password",
  "passwordConfirmation",
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
  id: keyof BusinessAdminFormValues;
  label: string;
  register: UseFormRegister<BusinessAdminFormValues>;
  error?: string;
  placeholder?: string;
  type?: "text" | "tel" | "email" | "password";
  autoComplete?: string;
}

function TextField({
  id,
  label,
  register,
  error,
  placeholder,
  type = "text",
  autoComplete,
}: TextFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        placeholder={placeholder}
        aria-invalid={error !== undefined}
        autoComplete={autoComplete}
        {...register(id)}
      />
      <FormError message={error} />
    </div>
  );
}