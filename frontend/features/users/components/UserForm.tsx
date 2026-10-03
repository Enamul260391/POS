"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import {
  Controller,
  useForm,
  useWatch,
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

import { getRole } from "@/features/roles/services/roleService";
import { UserPermissionEditor } from "@/features/users/components/UserPermissionEditor";
import {
  createUserSchema,
  EMPTY_USER_VALUES,
  toUserFormValues,
  USER_STATUS_OPTIONS,
  type UserFormValues,
} from "@/features/users/lib/userSchema";
import {
  EMPTY_PERMISSION_SELECTION,
  PERMISSION_MODE_OPTIONS,
  toOverrideInputs,
  type PermissionMode,
  type PermissionSelection,
} from "@/features/users/lib/userPermissions";
import { syncUserPermissionOverrides } from "@/features/users/services/userService";
import {
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import { useApiResource } from "@/lib/api/useApiResource";
import {
  selectDirectoryRoles,
  selectDirectoryUsers,
  useUserDirectoryStore,
} from "@/stores/useUserDirectoryStore";
import { selectPermissions, useRoleStore } from "@/stores/useRoleStore";
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

  // `useWatch` (not `form.watch`) so the React Compiler can memoise this
  // component, matching the other API-backed forms. The role is watched rather
  // than read once because it is the baseline the editor resolves "Inherited"
  // against: switching role must re-read that role's grants.
  const roleId = useWatch({ control, name: "roleId" });

  // Permission mode is held here rather than in the form schema: it is a second
  // request, not part of the user payload, so it must not end up in
  // `StoreUserRequest`'s validation path. The default is the first entry of
  // `PERMISSION_MODE_OPTIONS` ("By Default"), which preserves the previous
  // behaviour exactly — no override is ever submitted.
  //
  // The ticked capabilities are *derived* from the selected role further down
  // rather than stored outright, so that changing the role cannot leave the
  // previous role's ticks behind. Only the administrator's own deviations are
  // stored, and they are stamped with the role they were made against.
  const [permissionMode, setPermissionMode] =
    useState<PermissionMode>(PERMISSION_MODE_OPTIONS[0].value);

  // The permission catalogue and the selected role's grants are the baseline the
  // checkboxes start from. Both are read here rather than inside the editor,
  // because the save path needs the same baseline to diff the ticked capabilities
  // against — one read, one truth, no chance of the two disagreeing.
  const permissions = useRoleStore(selectPermissions);
  const loadPermissions = useRoleStore((state) => state.loadPermissions);
  useEffect(() => {
    void loadPermissions();
  }, [loadPermissions]);

  const loadRole = useCallback(
    (signal: AbortSignal) => getRole(roleId, { signal }),
    [roleId],
  );
  const role = useApiResource(loadRole, [roleId], {
    enabled: permissionMode === "customize" && roleId > 0,
  });

  /**
   * What the selected role grants, or `null` while that is unknown.
   *
   * `null` covers three distinct states, all of which mean "no usable baseline":
   * no role is selected, the role is still being read, or the role now on screen
   * is not the selected one. Collapsing them is safe because the editor is
   * disabled and nothing is submitted until a matching baseline exists.
   *
   * The `role.data.id` test is the crux of the role-change fix. `useApiResource`
   * deliberately keeps the previous value on screen while a reload is in flight —
   * right for a list, wrong here, because on `Admin -> Manager` it would hand
   * back *Admin's* grants and the editor would diff Manager's ticks against an
   * Admin baseline. Comparing the payload's own id against the selected id makes
   * that unrepresentable: a baseline can only ever be read while it belongs to
   * the currently selected role.
   */
  const grantedByRole = useMemo(() => {
    if (roleId <= 0 || role.data === null || role.data.id !== roleId) {
      return null;
    }
    return new Set(role.data.permissions.map((permission) => permission.id));
  }, [roleId, role.data]);

  /**
   * The administrator's manual changes to the ticks, stamped with the role they
   * were made against.
   *
   * Storing the role *with* the selection — rather than seeding the selection
   * from the role in an effect — is what makes a role switch correct by
   * construction: a stored selection is only ever shown for the role it was
   * stamped with, so on `Admin -> Manager` the Admin stamp no longer matches and
   * the ticks fall back to Manager's own grants. There is no merge path, so
   * Admin's customisation cannot survive into Manager, and no effect has to
   * remember to clear it.
   */
  const [customSelection, setCustomSelection] = useState<{
    roleId: number;
    selected: PermissionSelection;
  } | null>(null);

  /**
   * Drops a stored customisation when the role picker changes at all.
   *
   * The stamp check above already discards a selection made for a *different*
   * role, but re-picking the same role after clearing it would otherwise match
   * again and restore ticks the administrator had moved on from. Tracking the id
   * the selection was taken under and clearing on the transition covers that
   * case too, so "clear the role" really does reset the editor.
   *
   * Done during render rather than in an effect: an effect would let one frame
   * render the stale selection first, and it would cascade an extra pass.
   */
  const [selectionRoleId, setSelectionRoleId] = useState(roleId);
  if (selectionRoleId !== roleId) {
    setSelectionRoleId(roleId);
    setCustomSelection(null);
  }

  /**
   * The ticks actually rendered: the administrator's changes for this role, or
   * the role's own grants when there are none yet.
   *
   * A role that is still loading therefore renders the *new* role's permissions
   * the moment they arrive, and nothing at all while it does not — never the
   * previous role's, which is what a save would otherwise diff against.
   */
  const permissionSelection = useMemo(() => {
    if (customSelection !== null && customSelection.roleId === roleId) {
      return customSelection.selected;
    }
    return grantedByRole ?? EMPTY_PERMISSION_SELECTION;
  }, [customSelection, roleId, grantedByRole]);

  /** Records a toggle against the role it was made for. */
  const changePermissionSelection = useCallback(
    (selected: PermissionSelection) => {
      setCustomSelection({ roleId, selected });
    },
    [roleId],
  );

  /**
   * The mode that is actually in force.
   *
   * Permission mode is a decision *about* a role, so with no role selected there
   * is no mode to be in. Deriving the default here rather than resetting the
   * stored value means clearing the role restores "By Default" on the next
   * render, and picking a role again starts from "By Default" — instead of
   * silently reopening a customisation whose basis the administrator can no
   * longer see.
   */
  const effectivePermissionMode =
    roleId > 0 ? permissionMode : PERMISSION_MODE_OPTIONS[0].value;

  const save = handleSubmit(async (values) => {
    const store = useUserDirectoryStore.getState();
    try {
      if (target.mode === "add") {
        const created = await store.addUser(values);

        // The create payload carries the role only, so the permission differences
        // are stored by the endpoint the API exposes for them: create first, then
        // sync. The sync is skipped entirely in "By Default" mode — an absent
        // override set *is* that mode, so an empty sync would be a needless write
        // rather than a different outcome.
        //
        // `effectivePermissionMode` (not the raw state) is the test, so clearing
        // the role also suppresses the sync: a customisation is a decision about
        // a role, and with no role there is nothing to customise.
        if (effectivePermissionMode !== "customize") {
          onOpenChange(false);
          return;
        }

        // Diffed against the same baseline the checkboxes were seeded from, so
        // only genuine differences become overrides and a permission left as the
        // role had it stays inherited. `grantedByRole` is `null` if that role's
        // permissions never arrived, and `toOverrideInputs` then yields no
        // overrides at all rather than guessing a baseline.
        const overrides = toOverrideInputs(
          permissionSelection,
          grantedByRole,
          permissions,
        );
        if (overrides.length === 0) {
          onOpenChange(false);
          return;
        }

        try {
          await syncUserPermissionOverrides(created.id, overrides);
        } catch (failure) {
          // Partial failure: the account exists, the overrides do not. The dialog
          // stays open and says so by name, so the failure is never reported as
          // a success. Any field-level message from the sync is applied first,
          // then the partial-failure wording is set, because it is the one fact
          // the administrator cannot infer from the error bag alone.
          applyValidationErrors(failure, setError, USER_FORM_FIELDS);
          setError("root", {
            message: `${created.name} was created, but the permission overrides were not saved. Edit the user to set them again.`,
          });
          return;
        }

        onOpenChange(false);
        return;
      }

      await store.updateUser(target.user.id, values);
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
    // `disablePointerDismissal` is Base UI's own switch for outside-click
    // dismissal: the dialog ignores a press on the backdrop, so an accidental
    // click outside cannot discard a half-filled form. The X button, Cancel and
    // Escape are untouched, so every intentional close still works.
    <Dialog open onOpenChange={onOpenChange} disablePointerDismissal>
      {/* Same width as the Create role dialog, so switching between the two
          administration modals does not move the layout. The form body scrolls
          internally, so the permission list cannot grow the popup. */}
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit user" : "Add user"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Editing ${target.user.name} · account details, role and status.`
              : "New staff account — name, contact, role, permissions and a starting password."}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={save} noValidate className="grid gap-4">
          {errors.root?.message !== undefined && (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
              {errors.root.message}
            </p>
          )}

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
            {/* Permission section.

          Add mode only, and always visible: permission mode is a decision *about*
          a role, so with no role there is nothing to submit — but hiding the
          section entirely made the field look missing rather than unavailable.
          It is therefore always rendered and disabled until a role is chosen,
          which keeps its position in the form stable. */}
            {target.mode === "add" && (
              <UserPermissionEditor
                mode={effectivePermissionMode}
                onModeChange={setPermissionMode}
                selected={permissionSelection}
                onSelectedChange={changePermissionSelection}
                grantedByRole={grantedByRole}
                hasRole={roleId > 0}
              />
            )}
           
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
