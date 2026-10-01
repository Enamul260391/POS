"use client";

import { useState } from "react";
import { ArrowRight } from "lucide-react";

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
import { formatQuantity } from "@/features/pos/lib/format";
import {
  ADJUSTMENT_TYPE_OPTIONS,
  previewStock,
  type StockAdjustmentType,
} from "@/features/inventory/lib/stock";
import { adjustStock } from "@/features/inventory/services/stockService";
import { UNIT_OPTIONS } from "@/features/products/lib/productSchema";
import { useInventoryStore } from "@/stores/useInventoryStore";
import type { Product } from "@/types/product";
import { cn } from "cn";

interface StockAdjustmentDialogProps {
  product: Product;
  /** Reports the dialog's new open state; the screen unmounts us on `false`. */
  onOpenChange: (open: boolean) => void;
}

const QUANTITY_LABELS: Record<StockAdjustmentType, string> = {
  receive: "Quantity to receive",
  remove: "Quantity to remove",
  set: "Counted quantity",
};

/**
 * Stock adjustment dialog (Phase 7).
 *
 * Receive / remove / set-count are toggle cards (button semantics: keyboard
 * works, `aria-pressed` carries the state). The resulting quantity is
 * previewed live through `previewStock`, and the submit goes through the
 * `adjustStock` application service — the exact contract of the future
 * `POST /api/products/{product}/stock-adjustments` endpoint, so swapping the
 * mock for Laravel means replacing one call, not this dialog. The service
 * re-validates; the inline hint mirrors its rules purely for instant feedback.
 *
 * Mounted only while open, so every adjustment starts from a clean form. The
 * `product` snapshot stays true because a successful adjustment closes us.
 */
export function StockAdjustmentDialog({
  product,
  onOpenChange,
}: StockAdjustmentDialogProps) {
  const [type, setType] = useState<StockAdjustmentType>("receive");
  const [quantityText, setQuantityText] = useState("");
  const [note, setNote] = useState("");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const unitLabel =
    UNIT_OPTIONS.find((option) => option.value === product.unit)?.label
    ?? product.unit;

  const hasQuantity = quantityText.trim() !== "";
  const quantity = Number(quantityText.trim());
  const preview = hasQuantity ? previewStock(product.stock, type, quantity) : null;

  let quantityHint: string | null = null;
  if (hasQuantity) {
    if (!Number.isFinite(quantity) || quantity < 0) {
      quantityHint = "Enter a valid quantity.";
    } else if (type !== "set" && quantity <= 0) {
      quantityHint = "Quantity must be greater than 0.";
    } else if (type === "remove" && quantity > product.stock) {
      quantityHint = `Only ${formatQuantity(product.stock)} on hand.`;
    } else if (type === "set" && quantity === product.stock) {
      quantityHint = `Stock is already ${formatQuantity(product.stock)}.`;
    }
  }

  const canApply = preview !== null && quantityHint === null && !isSaving;

  const selectType = (next: StockAdjustmentType) => {
    setType(next);
    setErrorMessage(null);
  };

  const handleApply = async () => {
    if (!canApply) {
      return;
    }
    setErrorMessage(null);
    setIsSaving(true);
    try {
      const result = await adjustStock({
        productId: product.id,
        type,
        quantity,
        note: note.trim() === "" ? null : note.trim(),
      });
      if (!result.ok) {
        setErrorMessage(result.message);
        return;
      }

      // The response carries the product *after* the ledger write, so the
      // cached row and the balance on screen are the server's numbers. The
      // frontend never adds the delta to `product.stock` itself.
      useInventoryStore.getState().applyProductUpdate(result.product);
      useInventoryStore.getState().recordMovement(result.movement);
      // Re-read the ledger so the history below shows the new row in its real
      // position rather than prepended to a stale page.
      await useInventoryStore.getState().load({}, 1);
      onOpenChange(false);
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{product.name}</DialogTitle>
          <DialogDescription>
            Adjust stock · <span className="font-mono">{product.sku ?? "—"}</span>
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-3">
          <div className="flex items-baseline justify-between gap-2 rounded-lg bg-muted/60 px-3 py-2 text-sm">
            <span className="text-muted-foreground">On hand</span>
            <span className="font-medium tabular-nums">
              {formatQuantity(product.stock)}{" "}
              <span className="text-xs font-normal text-muted-foreground">
                {unitLabel}
              </span>
            </span>
          </div>

          <div role="group" aria-label="Adjustment type" className="grid gap-1.5">
            {ADJUSTMENT_TYPE_OPTIONS.map((option) => {
              const isSelected = type === option.value;
              return (
                <Button
                  key={option.value}
                  type="button"
                  variant={isSelected ? "default" : "outline"}
                  aria-pressed={isSelected}
                  onClick={() => selectType(option.value)}
                  className="h-auto w-full flex-col items-start justify-start gap-0.5 px-3 py-2 text-left whitespace-normal"
                >
                  <span className="text-sm font-medium">{option.label}</span>
                  <span
                    className={cn(
                      "text-xs font-normal",
                      isSelected
                        ? "text-primary-foreground/75"
                        : "text-muted-foreground",
                    )}
                  >
                    {option.hint}
                  </span>
                </Button>
              );
            })}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="stock-adjustment-quantity">
              {QUANTITY_LABELS[type]}{" "}
              <span className="font-normal text-muted-foreground">
                ({unitLabel})
              </span>
            </Label>
            <Input
              id="stock-adjustment-quantity"
              type="number"
              inputMode="decimal"
              min={0}
              step="any"
              autoFocus
              placeholder="0"
              aria-invalid={quantityHint !== null}
              value={quantityText}
              onChange={(event) => {
                setQuantityText(event.target.value);
                setErrorMessage(null);
              }}
              onKeyDown={(event) => {
                if (event.key === "Enter") {
                  event.preventDefault();
                  void handleApply();
                }
              }}
            />
            {quantityHint !== null && (
              <p className="text-xs text-destructive">{quantityHint}</p>
            )}
          </div>

          {preview !== null && quantityHint === null && (
            <div className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-sm">
              <span className="text-muted-foreground">Resulting stock</span>
              <span className="flex items-center gap-1.5 font-medium tabular-nums">
                {formatQuantity(product.stock)}
                <ArrowRight
                  className="size-3.5 text-muted-foreground"
                  aria-hidden="true"
                />
                {formatQuantity(preview)}
                <span className="text-xs font-normal text-muted-foreground">
                  {unitLabel}
                </span>
              </span>
            </div>
          )}

          <div className="grid gap-1.5">
            <Label htmlFor="stock-adjustment-note">Note (optional)</Label>
            <Input
              id="stock-adjustment-note"
              placeholder="Reason — delivery, damage, recount…"
              maxLength={120}
              value={note}
              onChange={(event) => setNote(event.target.value)}
            />
          </div>

          {errorMessage !== null && (
            <p className="text-xs text-destructive" role="alert">
              {errorMessage}
            </p>
          )}
        </div>

        <DialogFooter>
          <DialogClose render={<Button type="button" variant="outline" />}>
            Cancel
          </DialogClose>
          <Button
            type="button"
            onClick={() => void handleApply()}
            disabled={!canApply}
          >
            {isSaving ? "Applying…" : "Apply adjustment"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}