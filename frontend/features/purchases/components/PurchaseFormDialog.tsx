"use client";

import { useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Plus, Trash2 } from "lucide-react";
import {
  Controller,
  useFieldArray,
  useForm,
  useWatch,
  type FieldErrors,
  type Path,
  type UseFormRegister,
  type UseFormReturn,
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
import { formatMoney } from "@/features/pos/lib/format";
import {
  EMPTY_PURCHASE_LINE,
  EMPTY_PURCHASE_VALUES,
  purchaseSchema,
  type PurchaseFormInput,
} from "@/features/purchases/lib/purchaseSchema";
import {
  calculatePurchaseTotals,
  lineTotalOf,
  type PurchaseLineLike,
} from "@/features/purchases/lib/purchases";
import { PaymentStatusBadge } from "@/features/purchases/components/PaymentStatusBadge";
import { createPurchase } from "@/features/purchases/services/purchaseService";
import { selectProducts, useCatalogueStore } from "@/stores/useProductStore";
import { usePurchaseStore } from "@/stores/usePurchaseStore";
import { selectSuppliers, useSupplierStore } from "@/stores/useSupplierStore";

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

interface NumberFieldProps {
  /** Any form path backed by a numeric input (`taxRate`, `items.0.quantity`, …). */
  name: Path<PurchaseFormInput>;
  label: string;
  register: UseFormRegister<PurchaseFormInput>;
  error?: string;
  hint?: string;
}

/**
 * Numeric input registered uncontrolled with `valueAsNumber`: a blank `NaN`
 * renders as an empty field, so a forgotten cost fails as "Required" instead
 * of silently ordering for free. Optional money fields (MRP, selling price)
 * reuse the same input — an empty field stays `NaN` and the schema reads it
 * as absent (`null`), never `0`.
 */
function NumberField({ name, label, register, error, hint }: NumberFieldProps) {
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

interface PurchaseLineRowProps {
  index: number;
  form: UseFormReturn<PurchaseFormInput>;
  productOptions: readonly { value: string; label: string }[];
  errors: FieldErrors<PurchaseFormInput>;
  /** Live line total from the watched form values (preview only). */
  lineTotal: number;
  canRemove: boolean;
  onRemove: () => void;
  /** Pre-fills the cost from `product.costPrice` when a product is picked. */
  onProductChange: (index: number, productId: number) => void;
}

/** One line of the create-purchase form; all state lives in react-hook-form. */
function PurchaseLineRow({
  index,
  form,
  productOptions,
  errors,
  lineTotal,
  canRemove,
  onRemove,
  onProductChange,
}: PurchaseLineRowProps) {
  const { control, register } = form;
  const lineErrors = errors.items?.[index];

  return (
    <div className="grid gap-2 rounded-lg border border-border p-2.5">
      <div className="flex items-start gap-2">
        <div className="grid min-w-0 flex-1 gap-1.5">
          <Label htmlFor={`items.${index}.productId`}>Product</Label>
          <Controller
            control={control}
            name={`items.${index}.productId`}
            render={({ field }) => {
              const selected = productOptions.find(
                (option) => option.value === String(field.value),
              );
              return (
                <Select
                  value={field.value >= 1 ? String(field.value) : null}
                  onValueChange={(value) => {
                    const productId = Number(value);
                    field.onChange(productId);
                    onProductChange(index, productId);
                  }}
                >
                  <SelectTrigger
                    id={`items.${index}.productId`}
                    className="w-full"
                    aria-invalid={lineErrors?.productId !== undefined}
                  >
                    <span
                      className={
                        selected === undefined ? "text-muted-foreground" : undefined
                      }
                    >
                      {selected?.label ?? "Select a product"}
                    </span>
                  </SelectTrigger>
                  <SelectContent>
                    {productOptions.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              );
            }}
          />
          <FormError message={lineErrors?.productId?.message} />
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          className="mt-7"
          disabled={!canRemove}
          aria-disabled={!canRemove}
          aria-label="Remove line"
          onClick={onRemove}
        >
          <Trash2 aria-hidden="true" />
        </Button>
      </div>

      <div className="grid gap-2 sm:grid-cols-3">
        <NumberField
          name={`items.${index}.quantity`}
          label="Quantity"
          register={register}
          error={lineErrors?.quantity?.message}
        />
        <NumberField
          name={`items.${index}.unitCost`}
          label="Cost price"
          register={register}
          error={lineErrors?.unitCost?.message}
        />
        <NumberField
          name={`items.${index}.discount`}
          label="Discount"
          register={register}
          error={lineErrors?.discount?.message}
        />
        <NumberField
          name={`items.${index}.mrp`}
          label="MRP"
          register={register}
          error={lineErrors?.mrp?.message}
        />
        <NumberField
          name={`items.${index}.sellingPrice`}
          label="Selling price"
          register={register}
          error={lineErrors?.sellingPrice?.message}
        />
        <div className="grid gap-1.5">
          <Label htmlFor={`items.${index}.sellingCode`}>Selling code</Label>
          <Input
            id={`items.${index}.sellingCode`}
            placeholder="Optional"
            maxLength={50}
            aria-invalid={lineErrors?.sellingCode !== undefined}
            {...register(`items.${index}.sellingCode`)}
          />
          <FormError message={lineErrors?.sellingCode?.message} />
        </div>
      </div>

      <div className="flex items-center justify-between text-xs text-muted-foreground">
        <span>Line total</span>
        <span className="font-medium text-foreground tabular-nums">
          {formatMoney(lineTotal)}
        </span>
      </div>
    </div>
  );
}

/** Read-only `label … value` row of the totals preview. */
function TotalRow({
  label,
  value,
  strong,
  valueClassName,
}: {
  label: string;
  value: string;
  strong?: boolean;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-center justify-between gap-4 text-sm">
      <span className={strong ? "font-medium" : "text-muted-foreground"}>{label}</span>
      <span
        className={`tabular-nums ${strong ? "font-heading font-semibold" : "font-medium"} ${valueClassName ?? ""}`}
      >
        {value}
      </span>
    </div>
  );
}

interface PurchaseFormDialogProps {
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

/**
 * Create Purchase dialog (Phase 9, Phase 25).
 *
 * Validation is React Hook Form + Zod (`purchaseSchema`): one supplier, at
 * least one line, unique products, discounts that fit their line. The live
 * totals panel previews the order with the shared pure
 * `calculatePurchaseTotals`; the stored document is whatever `POST /purchases`
 * answers with — the API recalculates the totals, writes the rows and receives
 * the stock through its ledger inside one transaction.
 *
 * The dialog never touches stock or stores directly; a service failure
 * surfaces as an inline alert and keeps the form open. Mounted only while
 * open, so every open starts from fresh state.
 */
export function PurchaseFormDialog({ onOpenChange }: PurchaseFormDialogProps) {
  const suppliers = useSupplierStore(selectSuppliers);
  const products = useCatalogueStore(selectProducts);

  const supplierOptions = useMemo(
    () =>
      suppliers.map((supplier) => ({
        value: String(supplier.id),
        label: supplier.name,
      })),
    [suppliers],
  );
  // Services cannot be stocked, so they never appear on a purchase line.
  const productOptions = useMemo(
    () =>
      products
        .filter((product) => product.unit !== "service")
        .map((product) => ({
          value: String(product.id),
          label: `${product.name} (${product.sku})`,
        })),
    [products],
  );

  const form = useForm<PurchaseFormInput>({
    resolver: zodResolver(purchaseSchema),
    defaultValues: EMPTY_PURCHASE_VALUES,
  });
  const { control, handleSubmit, register, setValue, formState } = form;
  const { errors, isSubmitting } = formState;

  const { fields, append, remove } = useFieldArray({ control, name: "items" });

  // Any change re-renders once and refreshes the totals preview. Watched rows
  // are partial while the user types, so blanks are coerced to 0 here.
  const watched = useWatch({ control });
  const watchedLines = useMemo<PurchaseLineLike[]>(
    () =>
      (watched.items ?? []).map((line) => ({
        quantity: line.quantity ?? 0,
        unitCost: line.unitCost ?? 0,
        discount: line.discount ?? 0,
      })),
    [watched.items],
  );
  const totals = useMemo(
    () =>
      calculatePurchaseTotals({
        lines: watchedLines,
        taxRate: watched.taxRate ?? 0,
        paidAmount: watched.paidAmount ?? 0,
      }),
    [watchedLines, watched.taxRate, watched.paidAmount],
  );

  const [formError, setFormError] = useState<string | null>(null);

  const handleProductChange = (index: number, productId: number) => {
    const product = products.find((candidate) => candidate.id === productId);
    if (product !== undefined) {
      // Sensible default the buyer can still override for this order.
      setValue(`items.${index}.unitCost`, product.costPrice, {
        shouldDirty: true,
      });
    }
  };

  const save = handleSubmit(async (values) => {
    setFormError(null);
    const result = await createPurchase({
      supplierId: values.supplierId,
      lines: values.items.map((line) => ({
        productId: line.productId,
        quantity: line.quantity,
        unitCost: line.unitCost,
        discount: line.discount,
        // The schema output is `number | null`; the raw input may hold
        // `undefined`/`NaN` for untouched fields — the service normalises
        // both to `null` for the API payload.
        mrp: line.mrp ?? null,
        sellingPrice: line.sellingPrice ?? null,
        sellingCode: line.sellingCode,
      })),
      taxRate: values.taxRate,
      paidAmount: values.paidAmount,
      note: values.note,
    });
    if (!result.ok) {
      setFormError(result.message);
      return;
    }
    // The API response is the authoritative document (server totals, status
    // and lines) — prepend exactly what it returned, nothing is recomputed.
    usePurchaseStore.getState().addPurchase(result.purchase);
    onOpenChange(false);
  });

  return (
    <Dialog open disablePointerDismissal onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>New purchase</DialogTitle>
          <DialogDescription>
            Supplier → items → stock receipt → payment. Saving receives the
            stock through the inventory service.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={save} noValidate className="grid gap-4">
          <div className="grid max-h-[58dvh] gap-3 overflow-y-auto pr-1">
            <div className="grid gap-1.5">
              <Label htmlFor="supplierId">Supplier</Label>
              <Controller
                control={control}
                name="supplierId"
                render={({ field }) => {
                  const selected = supplierOptions.find(
                    (option) => option.value === String(field.value),
                  );
                  return (
                    <Select
                      value={field.value >= 1 ? String(field.value) : null}
                      onValueChange={(value) => field.onChange(Number(value))}
                    >
                      <SelectTrigger
                        id="supplierId"
                        className="w-full"
                        aria-invalid={errors.supplierId !== undefined}
                      >
                        <span
                          className={
                            selected === undefined ? "text-muted-foreground" : undefined
                          }
                        >
                          {selected?.label ?? "Select a supplier"}
                        </span>
                      </SelectTrigger>
                      <SelectContent>
                        {supplierOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  );
                }}
              />
              <FormError message={errors.supplierId?.message} />
            </div>

            <div className="grid gap-2">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium">Items</span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => append({ ...EMPTY_PURCHASE_LINE })}
                >
                  <Plus aria-hidden="true" />
                  Add row
                </Button>
              </div>
              <FormError message={errors.items?.message} />
              {fields.map((field, index) => (
                <PurchaseLineRow
                  key={field.id}
                  index={index}
                  form={form}
                  productOptions={productOptions}
                  errors={errors}
                  lineTotal={lineTotalOf(watchedLines[index] ?? EMPTY_PURCHASE_LINE)}
                  canRemove={fields.length > 1}
                  onRemove={() => remove(index)}
                  onProductChange={handleProductChange}
                />
              ))}
            </div>

            <div className="grid gap-3 rounded-lg border border-border p-2.5 sm:grid-cols-3">
              <NumberField
                name="taxRate"
                label="Tax / VAT %"
                register={register}
                error={errors.taxRate?.message}
                hint="Applied to the discounted subtotal."
              />
              <NumberField
                name="paidAmount"
                label="Paid now"
                register={register}
                error={errors.paidAmount?.message}
                hint="0 = unpaid; capped at the grand total."
              />
              <div className="grid gap-1.5">
                <Label htmlFor="note">Note</Label>
                <Input
                  id="note"
                  placeholder="Delivery challan no."
                  aria-invalid={errors.note !== undefined}
                  {...register("note")}
                />
                <FormError message={errors.note?.message} />
              </div>
            </div>

            <div className="grid gap-1 rounded-lg border border-border bg-muted/40 p-2.5">
              <TotalRow label="Subtotal" value={formatMoney(totals.subtotal)} />
              <TotalRow
                label="Discount"
                value={`−${formatMoney(totals.discountTotal)}`}
              />
              <TotalRow
                label={`Tax (VAT ${totals.taxRate}%)`}
                value={formatMoney(totals.taxTotal)}
              />
              <TotalRow
                label="Grand total"
                value={formatMoney(totals.grandTotal)}
                strong
              />
              <TotalRow label="Paid" value={formatMoney(totals.paidAmount)} />
              <div className="flex items-center justify-between gap-4 text-sm">
                <span className="text-muted-foreground">Due</span>
                <span className="flex items-center gap-2">
                  <span
                    className={`font-medium tabular-nums ${
                      totals.dueAmount > 0 ? "text-destructive" : ""
                    }`}
                  >
                    {formatMoney(totals.dueAmount)}
                  </span>
                  <PaymentStatusBadge status={totals.paymentStatus} />
                </span>
              </div>
            </div>

            {formError !== null && (
              <p className="text-xs text-destructive" role="alert">
                {formError}
              </p>
            )}
          </div>

          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>
              Cancel
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              Record purchase
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}