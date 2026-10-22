"use client";

import { useState } from "react";

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
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { formatMoney } from "@/features/pos/lib/format";
import type { PriceOption } from "@/types/priceOptions";

interface PriceSelectionDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  productName: string;
  options: PriceOption[];
  quantity: number;
  /**
   * Confirms the chosen option.
   *
   * Returns the failure message when the choice was refused (for example the
   * exact `please add another batch of this product` shortfall), or `null`
   * when it was accepted. A returned message is shown **inside the dialog**:
   * the screen behind the modal is not readable while the modal is open, so a
   * failure reported there would be lost.
   */
  onConfirm: (option: PriceOption) => string | null;
}

export function PriceSelectionDialog({
  open,
  onOpenChange,
  productName,
  options,
  quantity,
  onConfirm,
}: PriceSelectionDialogProps) {
  const [selectedValue, setSelectedValue] = useState<string | null>(
    options.length > 0 ? options[0].selling_price : null,
  );
  // The failure of the last confirmation attempt, shown inside the dialog.
  const [error, setError] = useState<string | null>(null);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Choose a price</DialogTitle>
          <DialogDescription>
            {productName} has multiple selling prices. Pick one before adding
            {quantity} {quantity === 1 ? "unit" : "units"} to the sale.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-3">
          <RadioGroup
            value={selectedValue}
            onValueChange={(value: string) => {
              setSelectedValue(value);
              // A new choice replaces the previous attempt: its failure no
              // longer describes what the cashier just picked.
              setError(null);
            }}
            className="grid w-full gap-2"
          >
            {options.map((option) => {
              const available = Number(option.available_quantity);
              // The radio is a bare control, so the option's text lives beside
              // it inside the row's <label> — the shared `RadioGroupItem` only
              // renders its own indicator and drops any children it is given.
              const optionId = `price-option-${option.batch_id}`;
              return (
                <label
                  key={option.batch_id}
                  htmlFor={optionId}
                  className="flex items-start gap-2 rounded-lg border border-input p-2.5 transition-colors cursor-pointer hover:bg-muted/50 has-[[data-checked]]:border-primary/50 has-[[data-checked]]:bg-primary/10"
                >
                  <RadioGroupItem
                    id={optionId}
                    value={option.selling_price}
                    className="mt-0.5"
                  />
                  <div className="grid flex-1 grid-cols-2 gap-2">
                    <div className="flex flex-col gap-0.5">
                      <span className="text-sm font-medium">
                        {formatMoney(Number(option.selling_price))}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        Batch {option.batch_number}
                      </span>
                    </div>
                    <div className="flex flex-col gap-0.5">
                      <span className="text-xs text-muted-foreground">
                        Available at this price
                      </span>
                      <span className="text-sm font-medium">
                        {available.toLocaleString()} unit
                        {available !== 1 ? "s" : ""}
                      </span>
                    </div>
                  </div>
                </label>
              );
            })}
          </RadioGroup>
        </div>

        {error !== null && (
          <p className="text-xs text-destructive" role="alert">
            {error}
          </p>
        )}

        <DialogFooter>
          <DialogClose render={<Button variant="outline">Cancel</Button>} />
          <Button
            onClick={() => {
              const selected = options.find(
                (o) => o.selling_price === selectedValue,
              );
              if (selected) {
                // A refusal keeps the dialog open and states the reason here.
                setError(onConfirm(selected) ?? null);
              }
            }}
            disabled={selectedValue === null}
          >
            Confirm
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
