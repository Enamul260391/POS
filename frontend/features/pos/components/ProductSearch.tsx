"use client";

import { useState, type KeyboardEvent, type RefObject } from "react";
import { Barcode, Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import {
  addScannedBarcode,
  describeCartFailure,
  type AddToSaleOutcome,
} from "@/features/pos/lib/catalog";
import type { PriceOption } from "@/types/priceOptions";
import type { Product } from "@/types/product";
import { cn } from "cn";

interface ProductSearchProps {
  /** Search text, controlled by `PosTerminal` (which owns filter state). */
  query: string;
  onQueryChange: (query: string) => void;
  /** Top result of the current filter: Enter in search adds it directly. */
  firstResult: Product | null;
  /** Adds the top result to the cart (handles price selection async). */
  onAdd: (product: Product) => Promise<AddToSaleOutcome>;
  searchRef: RefObject<HTMLInputElement | null>;
  barcodeRef: RefObject<HTMLInputElement | null>;
  /** Opens the price selector when a scanned product has several prices. */
  onPriceSelect?: (product: Product, options: PriceOption[], quantity: number) => void;
}

interface Feedback {
  tone: "success" | "error";
  text: string;
}

/**
 * Search field and barcode input — the two ways a product enters the sale.
 *
 * Keyboard workflow, minimum steps:
 * - `Enter` in search   → adds the top result, then clears the search
 * - `Enter` in barcode  → resolves the code and adds it (scanner friendly:
 *   hardware scanners type the code and press Enter), focus stays put
 * - `Escape` in search  → clears the search
 * - `F2` / `F3` (global)→ jump to barcode / search (handled by `PosTerminal`)
 *
 * Both paths call the same guarded helpers (`addProductToSale`,
 * `addScannedBarcode`), so stock and active rules are identical everywhere.
 * Results are announced through a polite live region that always occupies the
 * same height, so feedback never shifts the layout.
 */
export function ProductSearch({
  query,
  onQueryChange,
  firstResult,
  onAdd,
  searchRef,
  barcodeRef,
  onPriceSelect,
}: ProductSearchProps) {
  const [scanText, setScanText] = useState("");
  const [isScanning, setIsScanning] = useState(false);
  const [feedback, setFeedback] = useState<Feedback | null>(null);

  const handleSearchKeyDown = async (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Escape") {
      onQueryChange("");
      setFeedback(null);
      return;
    }
    if (event.key !== "Enter") {
      return;
    }
    event.preventDefault();
    if (query.trim() === "") {
      return;
    }
    if (firstResult === null) {
      setFeedback({ tone: "error", text: "No matching product." });
      return;
    }
    const outcome = await onAdd(firstResult);
    if (outcome.ok) {
      setFeedback({ tone: "success", text: `Added ${firstResult.name}.` });
      onQueryChange("");
      return;
    }
    if (outcome.message !== describeCartFailure("price-selection-required")) {
      setFeedback({ tone: "error", text: outcome.message });
    }
  };

  const handleScanKeyDown = async (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key !== "Enter" || isScanning) {
      return;
    }
    event.preventDefault();
    setIsScanning(true);
    try {
      const outcome = await addScannedBarcode(scanText, onPriceSelect);
      setFeedback(
        outcome.ok
          ? { tone: "success", text: `Added ${outcome.product.name}.` }
          : { tone: "error", text: outcome.message },
      );
      if (outcome.ok) {
        setScanText("");
      }
    } catch {
      setFeedback({ tone: "error", text: "Product lookup failed. Try again." });
    } finally {
      setIsScanning(false);
      // A scanner keeps firing: the field must be ready for the next code.
      barcodeRef.current?.focus();
    }
  };

  return (
    <div className="shrink-0 space-y-1.5">
      <div className="grid gap-1.5 sm:grid-cols-2">
        <div className="relative">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            ref={searchRef}
            type="search"
            className="pl-8"
            placeholder="Search name or SKU…"
            aria-label="Search products"
            value={query}
            onChange={(event) => onQueryChange(event.target.value)}
            onKeyDown={handleSearchKeyDown}
          />
        </div>

        <div className="relative">
          <Barcode
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            ref={barcodeRef}
            className="pl-8"
            placeholder="Scan barcode or type SKU"
            aria-label="Barcode input"
            autoComplete="off"
            autoFocus
            value={scanText}
            onChange={(event) => setScanText(event.target.value)}
            onKeyDown={handleScanKeyDown}
          />
        </div>
      </div>

      <div className="flex items-center justify-between gap-2">
        <p
          role="status"
          aria-live="polite"
          className={cn(
            "min-h-4 truncate text-xs",
            feedback === null
              ? "text-muted-foreground"
              : feedback.tone === "error"
                ? "text-destructive"
                : "text-success",
          )}
        >
          {feedback?.text ?? "Enter adds the top result."}
        </p>
        <p className="hidden shrink-0 items-center gap-1 text-xs text-muted-foreground sm:flex">
          <kbd className="rounded border bg-muted px-1 font-mono text-[10px]">F2</kbd>
          scan
          <kbd className="rounded border bg-muted px-1 font-mono text-[10px]">F3</kbd>
          search
        </p>
      </div>
    </div>
  );
}
