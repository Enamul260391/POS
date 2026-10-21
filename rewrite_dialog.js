const fs = require("fs");
const path = "d:\\Development\\Pos\\frontend\\features\\pos\\components\\PriceSelectionDialog.tsx";
const content = `"use client";

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
  onConfirm: (option: PriceOption) => void;
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
            onValueChange={setSelectedValue}
            className="grid w-full gap-2"
          >
            {options.map((option) => {
              const available = Number(option.available_quantity);
              return (
                <RadioGroupItem
                  key={option.batch_id}
                  value={option.selling_price}
                  className="grid grid-cols-4 gap-2 p-2 border border-input rounded-lg hover:bg-muted/50 data-[checked]:bg-primary/10 data-[checked]:border-primary/50"
                >
                  <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">
                      {formatMoney(Number(option.selling_price))}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      Batch {option.batch_number}
                    </span>
                  </div>
                  <div className="col-span-2 flex flex-col gap-0.5">
                    <span className="text-xs text-muted-foreground">
                      Available at this price
                    </span>
                    <span className="text-sm font-medium">
                      {available.toLocaleString()} unit
                      {available !== 1 ? "s" : ""}
                    </span>
                  </div>
                </RadioGroupItem>
              );
            })}
          </RadioGroup>
        </div>

        <DialogFooter>
          <DialogClose render={<Button variant="outline">Cancel</Button>} />
          <Button
            onClick={() => {
              const selected = options.find(
                (o) => o.selling_price === selectedValue,
              );
              if (selected) {
                onConfirm(selected);
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
`;
fs.writeFileSync(path, content);
console.log("Written");
