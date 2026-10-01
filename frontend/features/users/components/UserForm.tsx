"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import {
  Controller,
  useForm,
  type Control,
  type Path,
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
import { cn } from "cn";

import {
  createUserSchema,
  EMPTY_USER_VALUES,
  toUserFormValues,
  USER_STATUS_OPTIONS,
  type UserFormValues,
} from "@/features/users/lib/userSchema";
import {
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import {
  selectDirectoryRoles,
  selectDirectoryUsers,
  useUserDirectoryStore,
} from "@/stores/useUserDirectoryStore";
import type { User, UserRoleOption } from "@/types/user";

/** What the dialog was opened for: a brand new user or an existing row. */
export type UserFormTarget =
  | { mode: "add" }
  | { mode: "edit"; user: User };

/** Field names a `422` may address, in the frontend's camelCase spelling. */
const USER_FORM_FIELDS = [
  "name",
  "email",
  "phone",
  "branch",
  "status",
  "notes",
  "roleId",
  "password",
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
  id: Path<UserFormValues>;
  label: string;
  register: UseFormRegister<UserFormValues>;
  error?: string;
  placeholder?: string;
  type?: "text" | "tel" | "email" | "password";
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
  control: Control<UserFormValues>;
  options: readonly { value: string; label: string }[];
  error?: string;
}

/** Single-select bound to react-hook-form through `Controller`. */
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

interface RoleSelectFieldProps {
  control: Control<UserFormValues>;
  roles: readonly UserRoleOption[];
  error?: string;
}

/**
 * Role picker bound to react-hook-form. `Select` works in strings, so the
 * numeric `roleId` is converted on the way out and back in.
 */
function RoleSelectField({ control, roles, error }: RoleSelectFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor="roleId">Role</Label>
      <Controller
        control={control}
        name="roleId"
        render={({ field }) => {
          const selected = roles.find((role) => role.id === field.value);
          return (
            <Select
              value={field.value > 0 ? String(field.value) : null}
              onValueChange={(value) => {
                if (typeof value === "string" && value !== "") {
                  field.onChange(Number(value));
                } else {
                  field.onChange(0);
                }
              }}
            >
              <SelectTrigger
                id="roleId"
                className="w-full"
                aria-invalid={error !== undefined}
              >
                <span>{selected?.name ?? "Select role"}</span>
              </SelectTrigger>
              <SelectContent>
                {roles.map((role) => (
                  <SelectItem key={role.id} value={String(role.id)}>
                    <span className="flex flex-col">
                      <span>{role.name}</span>
                      <span className="text-xs text-muted-foreground">
                        {role.slug}
                      </span>
                    </span>
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
interface UserFormProps {
  target: UserFormTarget;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Add/Edit user dialog.
 *
 * Validation is React Hook Form + Zod: `createUserSchema` closes email and
 * phone uniqueness over the directory and pins `roleId` to a real role.
 *
 * A save now goes through the API, so the dialog follows the same failure
 * contract as every other API-backed form: Laravel's `422` error bag is mapped
 * back onto the offending input (it keys errors by column name — `role_id`,
 * `email` — and the translation happens in `@/lib/api/validation`), and
 * anything else is shown as a form-level message. The dialog closes only on
 * success, so a rejection leaves the entered values on screen.
 *
 * Mounted only while open, so every open starts from fresh state.
 */
export function UserForm({ target, onOpenChange }: UserFormProps) {
  const users = useUserDirectoryStore(selectDirectoryUsers);
  const roles = useUserDirectoryStore(selectDirectoryRoles);
  const editingId = target.mode === "edit" ? target.user.id : null;

  const schema = useMemo(
    () => createUserSchema(users, roles, editingId),
    [users, roles, editingId],
  );

  const {
    handleSubmit,
    register,
    control,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<UserFormValues>({
    resolver: zodResolver(schema),
    defaultValues:
      target.mode === "edit"
        ? toUserFormValues(target.user)
        : EMPTY_USER_VALUES,
  });

  const save = handleSubmit(async (values) => {
    const store = useUserDirectoryStore.getState();
    try {
      if (target.mode === "add") {
        await store.addUser(values);
      } else {
        await store.updateUser(target.user.id, values);
      }
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, USER_FORM_FIELDS);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    }
  });

  const isEdit = target.mode === "edit";

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit user" : "Add user"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Editing ${target.user.name} · account details, role and status.`
              : "New staff account — name, contact, role and a starting password."}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={save} noValidate className="grid gap-4">
          <div className="grid max-h-[55dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2">
            <TextField
              id="name"
              label="Full name"
              register={register}
              error={errors.name?.message}
              placeholder="Samira Akter"
            />
            <TextField
              id="email"
              label="Email"
              type="email"
              register={register}
              error={errors.email?.message}
              placeholder="samira@pos.local"
            />
            <TextField
              id="phone"
              label="Phone"
              type="tel"
              register={register}
              error={errors.phone?.message}
              placeholder="+8801710000001"
            />
            <SelectField
              name="status"
              label="Status"
              control={control}
              options={USER_STATUS_OPTIONS}
              error={errors.status?.message}
            />
            {/* Role picker.

          Same guard as the role filter: the catalogue is a capability-gated
          request, so an empty picker would be a field the user cannot possibly
          fill in. Without it the dialog shows a required dropdown that offers
          nothing. */}
            {roles.length > 0 && (
              <RoleSelectField
                control={control}
                roles={roles}
                error={errors.roleId?.message}
              />
            )}
            <TextField
              id="branch"
              label="Branch"
              register={register}
              error={errors.branch?.message}
              placeholder="Main – Dhaka"
            />
            <TextField
              id="password"
              label={isEdit ? "New password" : "Password"}
              type="password"
              register={register}
              error={errors.password?.message}
              placeholder={
                isEdit ? "Leave blank to keep current" : "At least 8 characters"
              }
            />
            <TextField
              id="confirmPassword"
              label="Confirm password"
              type="password"
              register={register}
              error={errors.confirmPassword?.message}
              placeholder="Repeat password"
            />
                      <div className="grid gap-1.5 sm:col-span-2">
              <Label htmlFor="notes">Notes</Label>
              <textarea
                id="notes"
                rows={3}
                placeholder="On call weekends"
                aria-invalid={errors.notes !== undefined}
                className={cn(
                  "flex w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30",
                  errors.notes !== undefined &&
                    "border-destructive ring-3 ring-destructive/20",
                )}
                {...register("notes")}
              />
              <FormError message={errors.notes?.message} />
            </div>
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isEdit ? "Save changes" : "Save user"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
