/** Purchase-flow types: the batch/price allocation a sale line resolves to. */

/** A single distinct selling price exposed by the server for a product. */
export interface PriceOption {
  /** Selling price, formatted as a decimal string (e.g. \"100.00\"). */
  selling_price: string;
  /** Id of the FIFO baseline batch at this price. */
  batch_id: number;
  /** Human readable batch number. */
  batch_number: string;
  /** Units still available at this price. */
  available_quantity: string;
}

/** How many distinct selling prices a product exposes. */
export function countDistinctPrices(options: readonly PriceOption[]): number {
  const seen = new Set<string>();
  for (const option of options) {
    seen.add(option.selling_price);
  }
  return seen.size;
}

/** The auto-selected price when only one distinct price exists. */
export function firstSellingPrice(options: readonly PriceOption[]): string | null {
  if (options.length === 0) {
    return null;
  }
  return options[0].selling_price;
}

/** The unique distinct prices, FIFO ordered, for selector rendering. */
export function distinctPrices(options: readonly PriceOption[]): readonly string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const option of options) {
    if (!seen.has(option.selling_price)) {
      seen.add(option.selling_price);
      out.push(option.selling_price);
    }
  }
  return out;
}

/** Exact backend shortfall copy; the POS must show this verbatim. */
export const INSUFFICIENT_BATCH_STOCK_MESSAGE =
  "please add another batch of this product";

/** Drops zero-stock options the API already excludes, as a client-side guard. */
export function eligiblePriceOptions(options: readonly PriceOption[]): PriceOption[] {
  return options.filter((option) => Number(option.available_quantity) > 0);
}
