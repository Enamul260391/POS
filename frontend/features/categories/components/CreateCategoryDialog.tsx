"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";

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
  categoryNameSchema,
  EMPTY_CATEGORY_NAME_VALUES,
  type CategoryNameFormValues,
} from "@/features/categories/lib/categorySchema";
import { createCategory } from "@/features/categories/services/categoryService";
import { applyValidationErrors, hasValidationErrors, messageOfApiError } from "@/lib/api/validation";
import type { Category } from "@/types/category";

interface CreateCategoryDialogProps {
  /** Reports the dialog's new open state; the caller unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
  /**
   * Called with the category the API created, so the caller can cache and select
   * it. The dialog never navigates and never touches its caller's other state.
   */
  onCreated: (category: Category) => void;
}

/** Field names a `422` may address; only `name` is a form input here. */
const CATEGORY_FORM_FIELDS = ["name"] as const;

/** Inline validation message rendered under the input. */
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
 * Create-category dialog (Product → Category quick-create).
 *
 * A single-purpose, mounted-only-while-open dialog: the caller keeps its own
 * form state untouched while this one is on top, so a category can be created in
 * the middle of filling a product without losing anything.
 *
 * Validation is React Hook Form + Zod for the shape; the API decides the rest. A
 * `422` from `StoreCategoryRequest` (notably a duplicate name) is mapped back
 * onto the input by `@/lib/api/validation` and anything else becomes a
 * form-level message, so the dialog stays open with the typed name intact and
 * the user can correct it and retry.
 *
 * The create call goes through the existing category service, and the created
 * category is handed back to the caller rather than cached here: the catalogue
 * store already exposes `cacheCategory`, so exactly one writer owns the list.
 */
export function CreateCategoryDialog({
  onOpenChange,
  onCreated,
}: CreateCategoryDialogProps) {
  // Guards a second submission: the button is disabled while this is `true`, and
  // the handler bails out too, so a repeated click can never reach the API
  // twice. Follows the same `isSaving` state convention as the other dialogs.
  const [isSaving, setIsSaving] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<CategoryNameFormValues>({
    resolver: zodResolver(categoryNameSchema),
    defaultValues: EMPTY_CATEGORY_NAME_VALUES,
  });

  const submit = handleSubmit(async (values) => {
    if (isSaving) {
      return;
    }
    setIsSaving(true);
    try {
      const created = await createCategory({ name: values.name.trim() });
      onCreated(created);
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, CATEGORY_FORM_FIELDS);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    } finally {
      setIsSaving(false);
    }
  });

  const isBusy = isSaving || isSubmitting;

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Create category</DialogTitle>
          <DialogDescription>
            Adds a new category to the catalogue and selects it.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} noValidate className="grid gap-4">
          <div className="grid gap-1.5">
            <Label htmlFor="category-name">Category name</Label>
            <Input
              id="category-name"
              autoFocus
              placeholder="e.g. Beverages"
              aria-invalid={errors.name !== undefined}
              {...register("name")}
            />
            <FormError message={errors.name?.message} />
          </div>

          {errors.root?.message !== undefined && (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            >
              {errors.root.message}
            </p>
          )}

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isBusy} aria-busy={isBusy}>
              {isBusy ? "Creating…" : "Create category"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
