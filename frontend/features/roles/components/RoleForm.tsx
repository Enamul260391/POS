"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm, type UseFormRegister } from "react-hook-form";

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
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "cn";

import { permissionsByGroup } from "@/features/roles/lib/permissionGroups";
import {
  createRoleSchema,
  EMPTY_ROLE_VALUES,
  ROLE_FORM_FIELDS,
  toRoleFormValues,
  type RoleFormValues,
} from "@/features/roles/lib/roleSchema";
import {
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import {
  selectPermissions,
  selectRoles,
  useRoleStore,
} from "@/stores/useRoleStore";
import type { Role } from "@/types/role";

/** What the dialog was opened for: a brand new role or an existing one. */
export type RoleFormTarget =
  | { mode: "create" }
  | { mode: "edit"; role: Role };

const TEXTAREA_CLASSNAME =
  "flex w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30";

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

type TextFieldId = "name" | "description";

interface TextFieldProps {
  id: TextFieldId;
  label: string;
  register: UseFormRegister<RoleFormValues>;
  error?: string;
  placeholder?: string;
  multiline?: boolean;
}

function TextField({
  id,
  label,
  register,
  error,
  placeholder,
  multiline = false,
}: TextFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {multiline ? (
        <textarea
          id={id}
          rows={3}
          placeholder={placeholder}
          aria-invalid={error !== undefined}
          className={cn(
            TEXTAREA_CLASSNAME,
            error !== undefined && "border-destructive ring-3 ring-destructive/20",
          )}
          {...register(id)}
        />
      ) : (
        <Input
          id={id}
          placeholder={placeholder}
          aria-invalid={error !== undefined}
          {...register(id)}
        />
      )}
      <FormError message={error} />
    </div>
  );
}
interface RoleFormProps {
  target: RoleFormTarget;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Create / Edit role dialog.
 *
 * Validation is React Hook Form + Zod, with Laravel as the real authority: a
 * `422` is mapped back onto the fields through `@/lib/api/validation`, and a
 * non-validation failure is shown as a banner. Permissions are the rows of the
 * API's global catalogue, so every id submitted is one the backend issued.
 *
 * There is no slug input: the API derives the handle from the name, so the
 * current one is shown as read-only context on an edit instead of being edited
 * into a value the server would discard. Mounted only while open, so every open
 * is fresh.
 */
export function RoleForm({ target, onOpenChange }: RoleFormProps) {
  const roles = useRoleStore(selectRoles);
  const permissions = useRoleStore(selectPermissions);
  const editingId = target.mode === "edit" ? target.role.id : null;

  const schema = useMemo(
    () => createRoleSchema(roles, permissions, editingId),
    [roles, permissions, editingId],
  );

  const {
    handleSubmit,
    register,
    control,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RoleFormValues>({
    resolver: zodResolver(schema),
    defaultValues:
      target.mode === "edit"
        ? toRoleFormValues(target.role)
        : EMPTY_ROLE_VALUES,
  });

  // Memoised on the catalogue so the group headers and the "N of M" total are
  // not rebuilt on every keystroke.
  const groups = useMemo(() => permissionsByGroup(permissions), [permissions]);
  const totalPermissions = permissions.length;

  const save = handleSubmit(async (values) => {
    const store = useRoleStore.getState();
    try {
      if (target.mode === "create") {
        await store.addRole(values);
      } else {
        await store.updateRole(target.role.id, values);
      }
      // Re-read the catalogue so a reseed that added a permission is picked up
      // before the next dialog opens.
      void store.loadPermissions(true);
      onOpenChange(false);
    } catch (cause) {
      // A `422` carries per-field messages; anything else is one banner.
      if (hasValidationErrors(cause)) {
        applyValidationErrors(cause, setError, ROLE_FORM_FIELDS);
        return;
      }
      setError("root", {
        message: messageOfApiError(cause, "Could not save the role."),
      });
    }
  });

  const isEdit = target.mode === "edit";

  return (
    // `disablePointerDismissal` is Base UI's own switch for outside-click
    // dismissal: the dialog ignores a press on the backdrop, so an accidental
    // click outside cannot discard a half-filled role. The X button, Cancel and
    // Escape are untouched, so every intentional close still works.
    <Dialog open onOpenChange={onOpenChange} disablePointerDismissal>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit role" : "Create role"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Editing ${target.role.name} · change its name and permission set.`
              : "New role — give it a name and the permissions it grants."}
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

          <div className="grid gap-x-3 gap-y-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="name">Name</Label>
              <Input
                id="name"
                placeholder="Manager"
                aria-invalid={errors.name !== undefined}
                {...register("name")}
              />
              <FormError message={errors.name?.message} />
              {isEdit && (
                <p className="text-xs text-muted-foreground">
                  Slug is generated from the name:{" "}
                  <span className="font-mono">{target.role.slug}</span>
                </p>
              )}
            </div>
            <div className="sm:col-span-2">
              <TextField
                id="description"
                label="Description"
                register={register}
                error={errors.description?.message}
                placeholder="Who this role is for…"
                multiline
              />
            </div>
          </div>


                    <div className="space-y-1">
            <p className="text-sm font-medium">Permissions</p>
            <p className="text-xs text-muted-foreground">
              Choose the areas this role can access.
            </p>
          </div>

          <Controller
            control={control}
            name="permissionIds"
            render={({ field }) => {
              const selected = new Set(field.value);
              const toggle = (id: number) => {
                const next = new Set(field.value);
                if (next.has(id)) {
                  next.delete(id);
                } else {
                  next.add(id);
                }
                field.onChange([...next]);
              };
              return (
                <div className="max-h-[40dvh] space-y-4 overflow-y-auto">
                  <p className="text-xs text-muted-foreground">
                    {selected.size} of {totalPermissions} permissions granted.
                  </p>
                  {groups.map((group) => {
                    const ids = group.permissions.map((p) => p.id);
                    const allSelected = ids.every((id) => selected.has(id));
                    return (
                      <div key={group.prefix} className="space-y-1">
                        <div className="flex items-center gap-2 text-sm font-medium">
                          <Checkbox
                            className="mt-0.5"
                            checked={allSelected}
                            onCheckedChange={() => {
                              const next = new Set(field.value);
                              ids.forEach((id) => {
                                if (allSelected) {
                                  next.delete(id);
                                } else {
                                  next.add(id);
                                }
                              });
                              field.onChange([...next]);
                            }}
                          />
                          <span>{group.group}</span>
                        </div>
                        {group.permissions.map((permission) => (
                          <label
                            key={permission.id}
                            className="flex items-start gap-2.5 rounded p-1.5 hover:bg-muted/50"
                          >
                            <Checkbox
                              className="mt-0.5"
                              checked={selected.has(permission.id)}
                              onCheckedChange={() => toggle(permission.id)}
                            />
                            <div className="min-w-0 flex-1">
                              <span className="block text-sm font-medium">
                                {permission.label}
                              </span>
                              {permission.description !== null && (
                                <span className="block text-xs text-muted-foreground">
                                  {permission.description}
                                </span>
                              )}
                            </div>
                          </label>
                        ))}
                      </div>
                    );
                  })}
                </div>
              );
            }}
          />

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isEdit ? "Save changes" : "Create role"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
