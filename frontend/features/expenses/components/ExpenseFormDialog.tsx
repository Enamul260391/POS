"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, useWatch } from "react-hook-form";

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
import { EXPENSE_PAYMENT_METHOD_OPTIONS } from "@/features/expenses/lib/expenses";
import {
  EMPTY_EXPENSE_VALUES,
  EXPENSE_FORM_FIELDS,
  expenseSchema,
  toExpenseFormValues,
  type ExpenseFormValues,
} from "@/features/expenses/lib/expenseSchema";
import {
  applyValidationErrors,
  hasValidationErrors,
  messageOfApiError,
} from "@/lib/api/validation";
import {
  selectExpenseCategories,
  useExpenseStore,
} from "@/stores/useExpenseStore";
import type { Expense } from "@/types/expense";

interface ExpenseFormDialogProps {
  mode: "create" | "edit";
  expense?: Expense | null;
  onOpenChange: (open: boolean) => void;
}

/** Inline validation message rendered under a field. */
function FormError({ message }: { message?: string }) {
  if (message === undefined) return null;
  return (
    <p className="text-xs text-destructive" role="alert">
      {message}
    </p>
  );
}

/**
 * Add/Edit expense dialog.
 *
 * The fields are exactly what `StoreExpenseRequest` / `UpdateExpenseRequest`
 * accept: category, amount, date, payment method, reference and description.
 * The mock's `title` and `provider` inputs are gone because the `expenses`
 * table has neither column — `reference` is the short label the API does
 * store, so it took the title's place.
 *
 * Saving goes through the API, so a server-side rejection is reported in the
 * dialog instead of silently doing nothing. Field-level `422` messages are
 * mapped back onto the matching inputs by `applyValidationErrors`; anything
 * else — including the business rule that refuses to change the financial
 * fields of an expense that has already affected a cash register — becomes a
 * form-level message. Mounted only while open, so every open starts fresh.
 */
export function ExpenseFormDialog({
  mode,
  expense,
  onOpenChange,
}: ExpenseFormDialogProps) {
  const createExpense = useExpenseStore((s) => s.createExpense);
  const updateExpense = useExpenseStore((s) => s.updateExpense);
  const categories = useExpenseStore(selectExpenseCategories);
  const isLoadingCategories = useExpenseStore((s) => s.isLoadingCategories);

  const {
    register,
    handleSubmit,
    setValue,
    setError,
    formState,
    control,
  } = useForm<ExpenseFormValues>({
    resolver: zodResolver(expenseSchema),
    defaultValues:
      mode === "edit" && expense
        ? toExpenseFormValues(expense)
        : EMPTY_EXPENSE_VALUES,
  });
  const errors = formState.errors;
  // `useWatch` (not `form.watch`) so the React Compiler can memoise the
  // component, matching `SalePaymentDialog` and `CloseRegisterDialog`.
  const methodValue = useWatch({ control, name: "paymentMethod" });
  const categoryValue = useWatch({ control, name: "categoryId" });

  // The select works in strings, so the numeric id is carried as text and
  // converted back on change.
  const categoryOptions = categories.map((category) => ({
    value: String(category.id),
    label: category.name,
  }));
  const selectedCategory =
    categoryOptions.find((option) => option.value === String(categoryValue))
      ?.label ?? "Uncategorised";

  async function onSubmit(values: ExpenseFormValues) {
    try {
      if (mode === "edit" && expense) {
        await updateExpense(expense.id, values);
      } else {
        await createExpense(values);
      }
      onOpenChange(false);
    } catch (error) {
      applyValidationErrors(error, setError, [...EXPENSE_FORM_FIELDS]);
      if (!hasValidationErrors(error)) {
        setError("root", { message: messageOfApiError(error) });
      }
    }
  }


  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {mode === "edit" ? "Edit expense" : "Add expense"}
          </DialogTitle>
          <DialogDescription>
            {mode === "edit"
              ? "Correct the details. Financial fields of an expense that already moved the drawer are locked by the server."
              : "Record shop spending. A cash expense posts its drawer movement in the same server transaction."}
          </DialogDescription>
        </DialogHeader>

        <form
          onSubmit={handleSubmit(onSubmit)}
          noValidate
          className="grid max-h-[70vh] gap-3 overflow-y-auto pr-0.5"
        >
          {errors.root?.message !== undefined && (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            >
              {errors.root.message}
            </p>
          )}

          <div className="grid gap-1.5">
            <Label htmlFor="reference">Reference</Label>
            <Input
              id="reference"
              placeholder="Receipt no. or a short label"
              aria-invalid={errors.reference !== undefined}
              {...register("reference")}
            />
            <FormError message={errors.reference?.message} />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label>Category</Label>
              <Select
                value={categoryValue === null ? "none" : String(categoryValue)}
                onValueChange={(value) =>
                  setValue("categoryId", value === "none" ? null : Number(value), {
                    shouldValidate: true,
                  })
                }
              >
                <SelectTrigger aria-label="Category" className="w-full">
                  <span
                    className={
                      categoryValue === null ? "text-muted-foreground" : undefined
                    }
                  >
                    {selectedCategory}
                  </span>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Uncategorised</SelectItem>
                  {categoryOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {isLoadingCategories && (
                <p className="text-xs text-muted-foreground">
                  Loading categories…
                </p>
              )}
              <FormError message={errors.categoryId?.message} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="amount">Amount (৳)</Label>
              <Input
                id="amount"
                type="number"
                inputMode="decimal"
                min={0}
                step="0.01"
                placeholder="0.00"
                aria-invalid={errors.amount !== undefined}
                {...register("amount", { valueAsNumber: true })}
              />
              <FormError message={errors.amount?.message} />
            </div>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="expenseDate">Date</Label>
              <Input
                id="expenseDate"
                type="date"
                aria-invalid={errors.expenseDate !== undefined}
                {...register("expenseDate")}
              />
              <FormError message={errors.expenseDate?.message} />
            </div>
            <div className="grid gap-1.5">
              <Label>Payment method</Label>
              <Select
                value={methodValue}
                onValueChange={(value) =>
                  setValue(
                    "paymentMethod",
                    value as ExpenseFormValues["paymentMethod"],
                    { shouldValidate: true },
                  )
                }
              >
                <SelectTrigger aria-label="Payment method" className="w-full">
                  <span>
                    {EXPENSE_PAYMENT_METHOD_OPTIONS.find(
                      (o) => o.value === methodValue,
                    )?.label ?? "Select"}
                  </span>
                </SelectTrigger>
                <SelectContent>
                  {EXPENSE_PAYMENT_METHOD_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                      {o.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormError message={errors.paymentMethod?.message} />
            </div>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="description">Description</Label>
            <Input
              id="description"
              placeholder="Receipt no., reason…"
              aria-invalid={errors.description !== undefined}
              {...register("description")}
            />
            <FormError message={errors.description?.message} />
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={formState.isSubmitting}>
              {mode === "edit" ? "Save changes" : "Add expense"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
