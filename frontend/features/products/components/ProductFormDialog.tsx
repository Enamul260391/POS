"use client";

import { useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { ImagePlus } from "lucide-react";
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
  SelectSeparator,
  SelectTrigger,
} from "@/components/ui/select";
import { CreateCategoryDialog } from "@/features/categories/components/CreateCategoryDialog";
import {
  baseProductSchema,
  EMPTY_PRODUCT_VALUES,
  STATUS_OPTIONS,
  toProductFormValues,
  UNIT_OPTIONS,
  type ProductFormValues,
} from "@/features/products/lib/productSchema";
import { categoryOptions } from "@/features/products/lib/products";
import { applyValidationErrors, hasValidationErrors, messageOfApiError } from "@/lib/api/validation";
import { selectCategories, useCatalogueStore } from "@/stores/useProductStore";
import type { Category } from "@/types/category";
import type { Product } from "@/types/product";
import { cn } from "cn";

/** What the dialog was opened for: a brand new product or an existing row. */
export type ProductFormTarget =
  | { mode: "add" }
  | { mode: "edit"; product: Product };

/**
 * Reserved value of a `SelectField`'s trailing action item.
 *
 * A category id is always numeric, so this string can never collide with a real
 * option, and `onValueChange` intercepts it before the form is written to.
 */
const ACTION_OPTION_VALUE = "__action__";

/** Label of the category select's trailing quick-create option. */
const CREATE_CATEGORY_LABEL = "+ Create new category";

/** Form fields backed by a numeric input. */
type NumericFieldName = "costPrice" | "sellingPrice" | "lowStockThreshold";

/** Form fields backed by a Select. */
type SelectFieldName = "categoryId" | "unit" | "status";

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
  id: "name" | "sku" | "barcode";
  label: string;
  register: UseFormRegister<ProductFormValues>;
  error?: string;
  placeholder?: string;
  className?: string;
}

/** Text input bound to react-hook-form through `register`. */
function TextField({
  id,
  label,
  register,
  error,
  placeholder,
  className,
}: TextFieldProps) {
  return (
    <div className={cn("grid gap-1.5", className)}>
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        placeholder={placeholder}
        aria-invalid={error !== undefined}
        {...register(id)}
      />
      <FormError message={error} />
    </div>
  );
}

interface NumberFieldProps {
  name: NumericFieldName;
  label: string;
  register: UseFormRegister<ProductFormValues>;
  error?: string;
  hint?: string;
}

/**
 * Numeric input registered uncontrolled with `valueAsNumber`.
 *
 * The blank default is `NaN`, which a `type="number"` box renders as an empty
 * field — a forgotten price then fails validation as "Required" — while real
 * values (edit mode) populate on mount. Uncontrolled also keeps typing free:
 * intermediate text like "12." is never clobbered by a re-render.
 */
function NumberField({
  name,
  label,
  register,
  error,
  hint,
}: NumberFieldProps) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Input
        id={name}
        type="number"
        inputMode="decimal"
        min={0}
        step="0.01"
        aria-invalid={error !== undefined}
        {...register(name, { valueAsNumber: true })}
      />
      {hint !== undefined && (
        <p className="text-xs text-muted-foreground">{hint}</p>
      )}
      <FormError message={error} />
    </div>
  );
}

interface SelectFieldProps {
  name: SelectFieldName;
  label: string;
  control: Control<ProductFormValues>;
  options: readonly { value: string; label: string }[];
  /** Shown while nothing valid is selected (category id `0`). */
  placeholder: string;
  /** Maps the picked string back to a number (category id only). */
  numeric?: boolean;
  error?: string;
  className?: string;
  /**
   * Optional action appended below a separator as the last option. Picking it
   * runs the callback instead of writing a value, so it never becomes a
   * selectable entry of the form.
   */
  action?: { label: string; onSelect: () => void };
}

/** Select bound to react-hook-form through `Controller`. */
function SelectField({
  name,
  label,
  control,
  options,
  placeholder,
  numeric,
  error,
  className,
  action,
}: SelectFieldProps) {
  return (
    <div className={cn("grid gap-1.5", className)}>
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
              onValueChange={(value) => {
                if (action !== undefined && value === ACTION_OPTION_VALUE) {
                  action.onSelect();
                  return;
                }
                field.onChange(numeric === true ? Number(value) : value);
              }}
            >
              <SelectTrigger
                id={name}
                className="w-full"
                aria-invalid={error !== undefined}
              >
                <span
                  className={
                    selected === undefined ? "text-muted-foreground" : undefined
                  }
                >
                  {selected?.label ?? placeholder}
                </span>
              </SelectTrigger>
              <SelectContent>
                {options.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
                {action !== undefined && (
                  <>
                    <SelectSeparator />
                    <SelectItem
                      value={ACTION_OPTION_VALUE}
                      className="font-medium text-primary"
                    >
                      {action.label}
                    </SelectItem>
                  </>
                )}
              </SelectContent>
            </Select>
          );
        }}
      />
      <FormError message={error} />
    </div>
  );
}

interface ProductFormDialogProps {
  target: ProductFormTarget;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/** Field names a `422` may address, in the frontend's camelCase spelling. */
const PRODUCT_FORM_FIELDS = [
  "name",
  "sku",
  "barcode",
  "categoryId",
  "costPrice",
  "sellingPrice",
  "unit",
  "lowStockThreshold",
  "status",
] as const;

/**
 * Add/Edit product dialog (Phase 7, Phase 25).
 *
 * Validation is React Hook Form + Zod for the shape, and the **API** for the
 * rules: SKU/barcode uniqueness is business-scoped, so `StoreProductRequest`
 * answers a duplicate with a `422` that is mapped back onto the field. The
 * client-side uniqueness check over the whole catalogue is gone — it would have
 * needed every product in memory to save one round trip.
 *
 * Saving posts to Laravel and updates the catalogue cache with the response.
 * The form never carries a quantity: a product is master data, and stock
 * enters the system through a purchase. There is therefore no stock field to
 * disable in edit mode — there is no stock field at all.
 *
 * Quick-create: the category select carries a trailing "+ Create new category"
 * action that opens {@link CreateCategoryDialog} on top of this dialog. The new
 * category is cached through the store's `cacheCategory` — so it also reaches
 * the table and the POS without a refetch — and then selected here. Nothing
 * else is touched: this form keeps its values, stays open, and saves exactly as
 * before through the same `createProduct` call.
 */
export function ProductFormDialog({ target, onOpenChange }: ProductFormDialogProps) {
  const categories = useCatalogueStore(selectCategories);
  const [isCreatingCategory, setIsCreatingCategory] = useState(false);

  const schema = useMemo(() => baseProductSchema, []);

  const {
    control,
    handleSubmit,
    register,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ProductFormValues>({
    resolver: zodResolver(schema),
    defaultValues:
      target.mode === "edit"
        ? toProductFormValues(target.product)
        : EMPTY_PRODUCT_VALUES,
  });

  const categorySelectOptions = useMemo(
    () =>
      categoryOptions(categories).map((option) => ({
        value: String(option.value),
        label: option.label,
      })),
    [categories],
  );

  /**
   * Selects a just-created category.
   *
   * The store owns the category list, so the new row is cached through its
   * existing `cacheCategory` action (upsert, no refetch) and the form field is
   * written to directly. The payload contract is untouched: `categoryId` is
   * still a number and still becomes `category_id` in `toProductInput`.
   */
  const applyCreatedCategory = (category: Category) => {
    useCatalogueStore.getState().cacheCategory(category);
    setValue("categoryId", category.id, { shouldValidate: true, shouldDirty: true });
  };

  /**
   * Saves through the API.
   *
   * Phase 25: the store is the cache, the API is the authority. A `422` from
   * `StoreProductRequest` / `UpdateProductRequest` is mapped back onto the
   * offending inputs (Laravel keys errors by column name — `category_id` —
   * so the translation happens in `@/lib/api/validation`), and anything else is
   * shown as a form-level message. The dialog only closes on success, so a
   * rejection leaves the entered values on screen.
   */
  const save = handleSubmit(async (values) => {
    const store = useCatalogueStore.getState();
    try {
      if (target.mode === "add") {
        await store.createProduct(values);
      } else {
        await store.updateProduct(target.product.id, values);
      }
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, PRODUCT_FORM_FIELDS);
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
          <DialogTitle>{isEdit ? "Edit product" : "Add product"}</DialogTitle>
          <DialogDescription>
            {isEdit ? (
              <>
                Editing <span className="font-mono">{target.product.sku}</span>{" "}
                · master data only.
              </>
            ) : (
              "New catalogue item — identity, pricing and stock setup."
            )}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={save} noValidate className="grid gap-4">
          <div className="grid max-h-[55dvh] gap-x-3 gap-y-3 overflow-y-auto pr-1 sm:grid-cols-2">
            <TextField
              id="name"
              label="Product name"
              register={register}
              error={errors.name?.message}
              placeholder="e.g. Coca-Cola 500ml PET Bottle"
              className="sm:col-span-2"
            />
            <TextField
              id="sku"
              label="SKU"
              register={register}
              error={errors.sku?.message}
              placeholder="BEV-COL-500"
            />
            <TextField
              id="barcode"
              label="Barcode (optional)"
              register={register}
              error={errors.barcode?.message}
              placeholder="8901234501234"
            />
            <SelectField
              name="categoryId"
              label="Category"
              control={control}
              options={categorySelectOptions}
              placeholder="Select a category"
              numeric
              error={errors.categoryId?.message}
              action={{
                label: CREATE_CATEGORY_LABEL,
                onSelect: () => {
                  setIsCreatingCategory(true);
                },
              }}
            />
            <SelectField
              name="unit"
              label="Unit"
              control={control}
              options={UNIT_OPTIONS}
              placeholder="Select unit"
              error={errors.unit?.message}
            />
            <SelectField
              name="status"
              label="Status"
              control={control}
              options={STATUS_OPTIONS}
              placeholder="Select status"
              error={errors.status?.message}
            />
            <NumberField
              name="lowStockThreshold"
              label="Low stock threshold"
              register={register}
              error={errors.lowStockThreshold?.message}
              hint="Alert when stock falls to or below this level."
            />
            <NumberField
              name="costPrice"
              label="Cost price"
              register={register}
              error={errors.costPrice?.message}
            />
            <NumberField
              name="sellingPrice"
              label="Selling price"
              register={register}
              error={errors.sellingPrice?.message}
            />

            <div className="grid gap-1.5 sm:col-span-2">
              <span className="text-sm font-medium">Image</span>
              <div className="flex items-center gap-3 rounded-lg border border-dashed border-border px-3 py-2.5">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  <ImagePlus className="size-4" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium">Product image</p>
                  <p className="text-xs text-muted-foreground">
                    Placeholder until file storage is available.
                  </p>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled
                  aria-disabled="true"
                >
                  Upload
                </Button>
              </div>
            </div>
            {errors.root?.message !== undefined && (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
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
              {isEdit ? "Save changes" : "Save product"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>

      {/*
       * A sibling of the product dialog, never a child of its form: two nested
       * `<form>` elements are invalid HTML and would break the submit button of
       * whichever one is inner. Mounted only while open, so it always starts
       * blank, and dismissing it leaves this form exactly as it was.
       */}
      {isCreatingCategory && (
        <CreateCategoryDialog
          onOpenChange={setIsCreatingCategory}
          onCreated={applyCreatedCategory}
        />
      )}
    </Dialog>
  );
}