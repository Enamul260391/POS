import { roundMoney, useCartStore, type CartActionFailure } from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { CartItem } from "@/types/cart";
import type { Product } from "@/types/product";

import {
  INSUFFICIENT_BATCH_STOCK_MESSAGE,
  eligiblePriceOptions,
  type PriceOption,
} from "@/types/priceOptions";
import { getPriceOptions } from "../services/priceOptionsService";

import { findProductByCode } from "../../products/services/productService";

/**
 * Catalogue helpers for the POS screen.
 *
 * This is the "product lookup" half of the flow:
 *
 *   Product Search / Barcode Scanner → Product Lookup → Product → Cart Store
 *
 * The screen resolves a `Product` here and hands it to the Phase 3 cart store,
 * so lookup and cart mutation stay decoupled and no component contains search
 * or stock rules.
 *
 * Phase 25: the module no longer imports `mock/products.json`. The catalogue
 * comes from `GET /products` and is passed in by `PosTerminal`, because a POS
 * must not read mock data. `findProductByCode` was replaced by the API lookup in
 * `addScannedBarcode`, which asks the backend for an exact barcode/SKU match
 * instead of scanning an in-memory array.
 */

const normalize = (value: string): string => value.trim().toLowerCase();

/**
 * Only active products may enter a sale.
 *
 * The search request already carries `status=active`, so this is a guard rather
 * than a filter: the POS must never sell an inactive row, and the sale endpoint
 * rejecting it at the till would be the worst possible place to find out.
 */
export function sellableProductsOf(products: readonly Product[]): readonly Product[] {
  return products.filter((product) => product.status === "active");
}

/**
 * Case-insensitive match against a product's barcode or SKU.
 *
 * Used by the search box so a typed SKU still resolves when the catalogue is
 * loaded; the scanner path itself goes through the API.
 */
export function matchesCode(product: Product, code: string): boolean {
  const needle = normalize(code);
  if (needle === "") {
    return false;
  }
  return (
    (product.barcode !== null && product.barcode.toLowerCase() === needle) ||
    (product.sku !== null && product.sku.toLowerCase() === needle)
  );
}

/** Sum of a product's quantity across every cart line. */
export function cartQuantityOf(items: readonly CartItem[], productId: number): number {
  let total = 0;
  for (const item of items) {
    if (item.productId === productId) {
      total += item.quantity;
    }
  }
  return total;
}

/** Quantity already in the cart for one product at one selling price. */
export function cartQuantityAtPrice(
  items: readonly CartItem[],
  productId: number,
  unitPrice: number,
): number {
  let total = 0;
  for (const item of items) {
    if (item.productId === productId && item.unitPrice === unitPrice) {
      total += item.quantity;
    }
  }
  return total;
}

/** Service products are not stock tracked; stock rules do not apply. */
export function isStockTracked(product: Product): boolean {
  return product.unit !== "service";
}

/** Units still sellable, or `null` when the product is not stock tracked. */
export function remainingStock(product: Product, inCartQuantity: number): number | null {
  if (!isStockTracked(product)) {
    return null;
  }
  return Math.max(0, product.stock - inCartQuantity);
}

/**
 * The single stock gate used by every add path (card tap, search Enter,
 * barcode scan). Blocks out-of-stock products and prevents exceeding stock
 * through repeated adds.
 */
export function canAddProduct(
  product: Product,
  inCartQuantity: number,
  quantity = 1,
): boolean {
  if (product.status !== "active") {
    return false;
  }
  if (!isStockTracked(product)) {
    return true;
  }
  return inCartQuantity + quantity <= product.stock;
}

/** Visual stock state of a product for the cashier. */
export type StockStatus = "service" | "out" | "low" | "in";

/** Classifies stock: untracked first, then out, low, in. */
export function stockStatus(product: Product, inCartQuantity: number): StockStatus {
  const remaining = remainingStock(product, inCartQuantity);
  if (remaining === null) {
    return "service";
  }
  if (remaining <= 0) {
    return "out";
  }
  if (remaining <= product.lowStockThreshold) {
    return "low";
  }
  return "in";
}

/** Friendly message for each way a cart action can be refused. */
export function describeCartFailure(reason: CartActionFailure): string {
  switch (reason) {
    case "invalid-product":
      return "Product information is missing.";
    case "product-not-active":
      return "This product is not active and cannot be sold.";
    case "invalid-quantity":
      return "That quantity is not valid.";
    case "invalid-unit-price":
      return "That price is not valid.";
    case "invalid-barcode":
      return "Scan or type a barcode first.";
    case "product-not-found":
      return "No product found for this code.";
    case "lookup-failed":
      return "Product lookup failed. Try again.";
    case "item-not-found":
      return "That cart line no longer exists.";
    case "price-selection-required":
      return "This product has multiple prices. Pick one before adding.";
    case "insufficient-batch-stock":
      return INSUFFICIENT_BATCH_STOCK_MESSAGE;
  }
}

/** Outcome of an add attempt, with a message the screen can show directly. */
export type AddToSaleOutcome =
  | { ok: true; product: Product }
  | { ok: false; message: string };

/**
 * Adds a resolved product to the cart after validating it against the master
 * data (active + stock). Used by the product card tap and by search Enter so
 * both paths share exactly one set of rules.
 */
export function addProductToSale(
  product: Product | null | undefined,
  quantity = 1,
): AddToSaleOutcome {
  if (product === null || product === undefined) {
    return { ok: false, message: describeCartFailure("invalid-product") };
  }
  if (product.status !== "active") {
    return { ok: false, message: describeCartFailure("product-not-active") };
  }

  const store = useCartStore.getState();
  const inCartQuantity = cartQuantityOf(store.items, product.id);
  if (!canAddProduct(product, inCartQuantity, quantity)) {
    const remaining = remainingStock(product, inCartQuantity) ?? 0;
    return {
      ok: false,
      message:
        remaining <= 0
          ? `${product.name} is out of stock.`
          : `Only ${remaining} left in stock.`,
    };
  }

  const result = store.addProduct(product, { quantity });
  return result.ok
    ? { ok: true, product }
    : { ok: false, message: describeCartFailure(result.reason) };
}

/**
 * Adds a product while the POS resolves its batch price options first.
 *
 * Outcomes matching the backend `resolveBatchLine` contract:
 *
 *   - untracked (service)  → no batches, add at the product selling price
 *   - zero ledger stock    → blocked before any request (`canAddProduct`)
 *   - no eligible options  → legacy product with ledger stock, no `batch_id`
 *   - one distinct price   → auto-selected, no selector, no `batch_id`
 *   - several distinct     → the caller must let the cashier choose first
 */
export async function addProductToSaleWithPriceOptions(
  product: Product | null | undefined,
  quantity = 1,
  onSelect?: (product: Product, options: PriceOption[], quantity: number) => void,
): Promise<AddToSaleOutcome> {
  if (product === null || product === undefined) {
    return { ok: false, message: describeCartFailure("invalid-product") };
  }
  if (product.status !== "active") {
    return { ok: false, message: describeCartFailure("product-not-active") };
  }

  if (!isStockTracked(product)) {
    return addProductToSale(product, quantity);
  }

  const store = useCartStore.getState();
  const inCartQuantity = cartQuantityOf(store.items, product.id);
  if (!canAddProduct(product, inCartQuantity, quantity)) {
    const remaining = remainingStock(product, inCartQuantity) ?? 0;
    return {
      ok: false,
      message:
        remaining <= 0
          ? `${product.name} is out of stock.`
          : `Only ${remaining} left in stock.`,
    };
  }

  let options: PriceOption[];
  try {
    options = eligiblePriceOptions(await getPriceOptions(product.id));
  } catch (error) {
    const message =
      typeof error === "object" &&
      error !== null &&
      "message" in error &&
      typeof (error as { message: unknown }).message === "string" &&
      (error as { message: string }).message.trim() !== ""
        ? (error as { message: string }).message
        : describeCartFailure("lookup-failed");
    return { ok: false, message };
  }

  if (options.length === 0) {
    // Empty options with remaining ledger stock is the legacy path: the
    // product has no batch history. Depleted-history leftovers are refused
    // at checkout with the backend's 409 wording.
    return addProductToSale(product, quantity);
  }

  if (options.length === 1) {
    return addChosenPriceToSale(product, options[0], quantity, null);
  }

  onSelect?.(product, options, quantity);
  return { ok: false, message: describeCartFailure("price-selection-required") };
}

/**
 * Adds at a cashier-chosen (or auto-selected) selling price.
 *
 * `batchId` is `null` for auto-selection so the backend FIFO-allocates within
 * the confirmed price. An explicit `batch_id` is sent only when several
 * distinct prices forced a choice.
 */
export function addChosenPriceToSale(
  product: Product,
  option: PriceOption,
  quantity = 1,
  batchId: number | null = option.batch_id,
): AddToSaleOutcome {
  const unitPrice = roundMoney(Number(option.selling_price));
  const available = Number(option.available_quantity);
  const store = useCartStore.getState();
  const inCartQuantity = cartQuantityOf(store.items, product.id);
  if (!canAddProduct(product, inCartQuantity, quantity)) {
    const remaining = remainingStock(product, inCartQuantity) ?? 0;
    return {
      ok: false,
      message:
        remaining <= 0
          ? `${product.name} is out of stock.`
          : `Only ${remaining} left in stock.`,
    };
  }

  if (!Number.isFinite(unitPrice) || unitPrice < 0 || !Number.isFinite(available)) {
    return { ok: false, message: describeCartFailure("invalid-unit-price") };
  }

  const inCartAtPrice = cartQuantityAtPrice(store.items, product.id, unitPrice);
  if (inCartAtPrice + quantity > available) {
    return { ok: false, message: INSUFFICIENT_BATCH_STOCK_MESSAGE };
  }

  const result = store.addProduct(product, {
    quantity,
    unitPrice,
    batchId,
    priceAvailableQuantity: available,
  });
  return result.ok
    ? { ok: true, product }
    : { ok: false, message: describeCartFailure(result.reason) };
}

/**
 * Barcode scanner entry point: resolves the code, checks stock, then lets the
 * cart store mutate through its injected lookup.
 *
 * Phase 25: the lookup is now a **server** call (`findProductByCode` in
 * `productService`), using the dedicated `barcode` / `sku` query parameters so
 * the match is an indexed equality rather than a scan of the in-memory
 * catalogue. The cart store still owns the mutation and never learns how a
 * product is found, so no component changes.
 *
 * The lookup is deliberately restricted to active products: `status=active` in
 * the query is what makes an inactive product report as "not found" here,
 * rather than being resolved and then rejected.
 */
export async function addScannedBarcode(
  barcode: string,
  onSelect?: (product: Product, options: PriceOption[], quantity: number) => void,
): Promise<AddToSaleOutcome> {
  const code = barcode.trim();
  if (code === "") {
    return { ok: false, message: describeCartFailure("invalid-barcode") };
  }

  let product: Product | null;
  try {
    product = await findProductByCode(code, { status: "active" });
  } catch {
    // A network/permission failure is a different problem from "no such code",
    // and the cashier needs to know which one it is.
    return { ok: false, message: describeCartFailure("lookup-failed") };
  }

  if (product === null) {
    return { ok: false, message: describeCartFailure("product-not-found") };
  }

  // The POS holds no catalogue, so cache the resolved row: a cart line reads its
  // product's stock from the catalogue store to show "N left" and to disable the
  // increase button at the stock limit. Without this, a scanned product would
  // have no master data at all. Cache write only — no request, no `isLoaded`.
  useCatalogueStore.getState().cacheProducts([product]);

  const store = useCartStore.getState();
  const inCartQuantity = cartQuantityOf(store.items, product.id);
  if (!canAddProduct(product, inCartQuantity, 1)) {
    const remaining = remainingStock(product, inCartQuantity) ?? 0;
    return {
      ok: false,
      message:
        remaining <= 0
          ? `${product.name} is out of stock.`
          : `Only ${remaining} left in stock.`,
    };
  }

  return addProductToSaleWithPriceOptions(product, 1, onSelect);
}
