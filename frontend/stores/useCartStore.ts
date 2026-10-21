import { useMemo } from "react";
import { create } from "zustand";

import type { CartItem, CartItemProduct } from "@/types/cart";
import type { Product, ProductUnit } from "@/types/product";

/**
 * Global cart store for the POS terminal (Phase 3).
 *
 * Architecture
 * ------------
 * Product master data and cart state are deliberately kept apart:
 *
 *   Product Search / Barcode Scanner
 *           │
 *           ▼
 *   Product Lookup            (services / products store - NOT this file)
 *           │
 *           ▼
 *   Product                   (an already resolved `Product` value)
 *           │
 *           ▼
 *   Cart Store                (this file: line snapshots + cart settings)
 *           │
 *           ▼
 *   Totals                    (derived on read, never stored)
 *
 * The store never fetches, caches or searches products. It receives a resolved
 * `Product`, validates it at runtime and copies the fields a cashier needs into
 * a line level snapshot. Scanning is supported through `addProductByBarcode`,
 * but the lookup itself is injected as a resolver function, so the store works
 * with the mock catalogue today and with the Laravel REST API later without a
 * single change to its public API.
 *
 * Derived values (subtotal, discounts, tax, grand total) are never stored:
 * `calculateCartTotals` is a pure function and `useCartTotals` memoises it for
 * components. Field names mirror `Sale` (`@/types/sale`), so the same totals can
 * be posted to Laravel once checkout is built.
 *
 * Money handling
 * --------------
 * Money is normalised to integer minor units (poisha) for every step and
 * converted back once per line. That keeps rounding predictable and avoids
 * floating point drift such as `0.1 + 0.2`. The global discount is allocated
 * across lines proportionally, remaining exact in minor units, so per line tax
 * always adds up to the invoice tax total.
 *
 * Scope
 * -----
 * No persistence, no POS UI and no payment modal live here; those are later
 * phases. `useCartStore` exposes hooks, so only client components may call them.
 */

/** Minor units per major currency unit (BDT: 100 poisha = 1 taka). */
const MINOR_UNITS_PER_MAJOR = 100;

/** Decimals kept for fractional quantities (kilogram, liter, ...). */
const FRACTIONAL_QUANTITY_DECIMALS = 3;

/** Hard ceiling per line, guarding against a mis-keyed or repeated scan. */
const MAX_QUANTITY_PER_LINE = 100_000;

/** Quantity used when a product is added without an explicit quantity. */
const DEFAULT_QUANTITY = 1;

/** Highest VAT percentage the cart accepts. */
const MAX_TAX_RATE = 100;

/** Units that are weighed or measured and therefore allow fractions. */
const FRACTIONAL_UNITS: ReadonlySet<ProductUnit> = new Set<ProductUnit>([
  "kilogram",
  "gram",
  "liter",
  "milliliter",
]);

/** Step used by increase/decrease quantity for each unit. */
const QUANTITY_STEPS: Readonly<Record<ProductUnit, number>> = {
  piece: 1,
  pack: 1,
  box: 1,
  carton: 1,
  dozen: 1,
  bottle: 1,
  service: 1,
  kilogram: 0.5,
  liter: 0.5,
  gram: 1,
  milliliter: 1,
};

/**
 * Converts a major unit amount into integer minor units (poisha).
 *
 * This is the only place money is scaled up, so every further addition or
 * allocation happens on integers and cannot drift.
 */
export function toMinorUnits(amount: number): number {
  return Number.isFinite(amount) ? Math.round(amount * MINOR_UNITS_PER_MAJOR) : 0;
}

/** Converts integer minor units (poisha) back into a major unit amount. */
export function fromMinorUnits(minorUnits: number): number {
  return Number.isFinite(minorUnits) ? minorUnits / MINOR_UNITS_PER_MAJOR : 0;
}

/** Rounds a monetary amount to two decimals through the minor unit grid. */
export function roundMoney(amount: number): number {
  return fromMinorUnits(toMinorUnits(amount));
}

/** Rounds a non monetary value to a fixed number of decimals. */
function roundTo(value: number, decimals: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }

  const factor = 10 ** decimals;
  return Math.round(value * factor) / factor;
}

/** Rounds a quantity to the precision the cart keeps for fractional units. */
function roundQuantity(quantity: number): number {
  return roundTo(quantity, FRACTIONAL_QUANTITY_DECIMALS);
}

/**
 * Clamps a number into `[min, max]`.
 *
 * Non finite input falls back to `min` and an inverted range is treated as
 * empty, so a clamp can never return an out of range or negative value.
 */
function clamp(value: number, min: number, max: number): number {
  if (!Number.isFinite(value) || max < min) {
    return min;
  }

  return Math.min(Math.max(value, min), max);
}

/**
 * `true` when the unit is sold by weight or volume and may hold fractions.
 *
 * Unknown units are treated as discrete, which is the safe default: a cashier
 * can never end up with half a piece of an unrecognised unit.
 */
export function isFractionalUnit(unit: ProductUnit): boolean {
  return FRACTIONAL_UNITS.has(unit);
}

/** Default step for one increase/decrease click, per unit of measure. */
export function quantityStep(unit: ProductUnit): number {
  const step: number | undefined = QUANTITY_STEPS[unit];
  return Number.isFinite(step) && step !== undefined && step > 0 ? step : DEFAULT_QUANTITY;
}

/**
 * Validates and normalises a quantity for a unit of measure.
 *
 * Returns `null` for anything unusable (not a finite number, `0`, negative,
 * `NaN`, `Infinity`). Fractions are kept for weighed/measured units and floored
 * for discrete ones, so a line always holds a sellable amount. The result is
 * capped at `MAX_QUANTITY_PER_LINE`.
 */
export function normalizeQuantity(quantity: number, unit: ProductUnit): number | null {
  if (!Number.isFinite(quantity) || quantity <= 0) {
    return null;
  }

  const normalized = isFractionalUnit(unit) ? roundQuantity(quantity) : Math.floor(quantity);

  if (normalized <= 0) {
    return null;
  }

  return Math.min(normalized, MAX_QUANTITY_PER_LINE);
}

/** Validates a unit price. Returns `null` for negative or non finite values. */
function normalizeUnitPrice(unitPrice: number): number | null {
  return Number.isFinite(unitPrice) && unitPrice >= 0 ? roundMoney(unitPrice) : null;
}

/**
 * Builds the product snapshot stored on a cart line.
 *
 * The snapshot is a **copy** of the master data, not a reference: a running
 * sale keeps its own names and prices even if the catalogue is refetched, the
 * network drops or the product is edited mid-sale. Returns `null` when the
 * value cannot be sold, which covers malformed API payloads without `any`.
 */
function toCartItemProduct(product: Product | null | undefined): CartItemProduct | null {
  if (!product || typeof product !== "object") {
    return null;
  }

  if (!Number.isFinite(product.id) || product.id <= 0) {
    return null;
  }

  if (typeof product.name !== "string" || !product.name.trim()) {
    return null;
  }

  if (typeof product.unit !== "string" || !product.unit) {
    return null;
  }

  if (!Number.isFinite(product.sellingPrice) || product.sellingPrice < 0) {
    return null;
  }

  return {
    id: product.id,
    name: product.name,
    sku: typeof product.sku === "string" ? product.sku : "",
    barcode: typeof product.barcode === "string" && product.barcode.trim() ? product.barcode : null,
    unit: product.unit,
    costPrice:
      Number.isFinite(product.costPrice) && product.costPrice >= 0 ? roundMoney(product.costPrice) : 0,
    sellingPrice: roundMoney(product.sellingPrice),
    image: typeof product.image === "string" && product.image.trim() ? product.image : null,
    status: product.status,
  };
}

/**
 * Keeps a stored line discount inside `[0, line gross]`.
 *
 * Called whenever the quantity changes, so the invariant "a line discount can
 * never exceed what the line is worth" holds at all times and no negative
 * amount can ever reach the totals.
 */
function clampLineDiscount(item: CartItem, quantity: number): number {
  return clamp(roundMoney(item.discount), 0, roundMoney(quantity * item.unitPrice));
}

/** Monotonic suffix so two lines created in the same millisecond stay unique. */
let lineSequence = 0;

/** Creates a client side line id (a product may legitimately span several lines). */
function createItemId(): string {
  lineSequence += 1;
  return `cart-line-${Date.now().toString(36)}-${lineSequence.toString(36)}`;
}

/**
 * Totals of a single cart line, calculated on demand.
 *
 * The tax chain is intentionally explicit and identical for every line:
 *
 *   gross        = quantity * unitPrice
 *   discount     = item discount, clamped to `[0, gross]`
 *   share        = this line's part of the global discount
 *   taxableBase  = gross - discount - share
 *   taxAmount    = taxableBase * taxRate / 100, rounded to 2 decimals
 *   lineTotal    = taxableBase + taxAmount
 *
 * Every amount is derived from integer minor units, so `lineTotal` values always
 * add up to `CartTotals.grandTotal` exactly.
 */
export interface CartLineTotals {
  /** Cart line the amounts belong to. */
  itemId: string;
  /** Product the line points to. */
  productId: number;
  quantity: number;
  unitPrice: number;
  /**
   * Batch row the line was allocated from, when an explicit batch was chosen.
   * `null` means the automatic price selection applied (the backend splits
   * across batches with FIFO at the server-confirmed price) — no `batch_id`
   * is sent to the sale endpoint.
   */
  batchId: number | null;
  /** Amount before any discount or tax. */
  gross: number;
  /** Item wise discount applied to this line. */
  discount: number;
  /** Part of the global discount allocated to this line. */
  globalDiscountShare: number;
  /** `discount + globalDiscountShare`. */
  discountTotal: number;
  /** Amount tax is charged on: `gross - discountTotal`. */
  taxableBase: number;
  /** VAT percentage used for this line. */
  taxRate: number;
  /** Tax charged for this line. */
  taxAmount: number;
  /** Amount payable for this line: `taxableBase + taxAmount`. */
  lineTotal: number;
}

/**
 * Invoice level totals.
 *
 * Field names match `Sale` (`@/types/sale`) so checkout can post them to the
 * Laravel API unchanged and the API stays the source of truth for business
 * rules such as stock movement.
 */
export interface CartTotals {
  /** Per line breakdown, in cart order. */
  lines: CartLineTotals[];
  /** Number of lines currently in the cart. */
  lineCount: number;
  /** Sum of all quantities. Informational: units may differ per line. */
  quantityTotal: number;
  /** Sum of all line amounts before discount and tax. */
  subtotal: number;
  /** Sum of all item wise discounts. */
  itemDiscountTotal: number;
  /** Global discount actually applied after clamping to the net amount. */
  globalDiscount: number;
  /** `itemDiscountTotal + globalDiscount`. */
  discountTotal: number;
  /** VAT percentage applied to every line. */
  taxRate: number;
  /** Sum of all line taxes. */
  taxTotal: number;
  /** Amount the customer has to pay: `subtotal - discountTotal + taxTotal`. */
  grandTotal: number;
}

/**
 * Calculates every cart total from the current lines and settings.
 *
 * Pure and side effect free, so it can be unit tested, memoised in components
 * or reused to build a sale payload without touching the store. The global
 * discount is clamped to what is actually left after item wise discounts, lines
 * are never taxed on a negative base, and the allocated shares add up to the
 * applied global discount exactly.
 */
export function calculateCartTotals(
  items: readonly CartItem[],
  globalDiscount: number,
  taxRate: number,
): CartTotals {
  const appliedTaxRate = clamp(Number.isFinite(taxRate) ? taxRate : 0, 0, MAX_TAX_RATE);

  const drafts = items.map((item) => {
    const grossMinorUnits = toMinorUnits(item.quantity * item.unitPrice);
    const discountMinorUnits = clamp(toMinorUnits(item.discount), 0, grossMinorUnits);

    return { item, grossMinorUnits, discountMinorUnits };
  });

  const subtotalMinorUnits = drafts.reduce((total, draft) => total + draft.grossMinorUnits, 0);
  const itemDiscountMinorUnits = drafts.reduce((total, draft) => total + draft.discountMinorUnits, 0);
  const netMinorUnits = subtotalMinorUnits - itemDiscountMinorUnits;

  const globalDiscountMinorUnits = clamp(toMinorUnits(globalDiscount), 0, netMinorUnits);

  let remainingDiscountMinorUnits = globalDiscountMinorUnits;
  let remainingNetMinorUnits = netMinorUnits;
  let taxTotalMinorUnits = 0;
  let grandTotalMinorUnits = 0;

  const lines: CartLineTotals[] = drafts.map((draft, index) => {
    const lineNetMinorUnits = draft.grossMinorUnits - draft.discountMinorUnits;
    const isLastLine = index === drafts.length - 1;

    // Proportional allocation; the last line absorbs the rounding remainder, so
    // the shares always sum to the applied global discount.
    const shareMinorUnits =
      isLastLine || remainingNetMinorUnits <= 0
        ? Math.min(remainingDiscountMinorUnits, lineNetMinorUnits)
        : clamp(
            Math.round((remainingDiscountMinorUnits * lineNetMinorUnits) / remainingNetMinorUnits),
            0,
            Math.min(remainingDiscountMinorUnits, lineNetMinorUnits),
          );

    remainingDiscountMinorUnits -= shareMinorUnits;
    remainingNetMinorUnits -= lineNetMinorUnits;

    const taxableBaseMinorUnits = lineNetMinorUnits - shareMinorUnits;
    const lineTaxMinorUnits = Math.round((taxableBaseMinorUnits * appliedTaxRate) / 100);
    const lineTotalMinorUnits = taxableBaseMinorUnits + lineTaxMinorUnits;

    taxTotalMinorUnits += lineTaxMinorUnits;
    grandTotalMinorUnits += lineTotalMinorUnits;

    return {
      itemId: draft.item.id,
      productId: draft.item.productId,
      quantity: draft.item.quantity,
      unitPrice: draft.item.unitPrice,
      batchId: draft.item.batchId,
      gross: fromMinorUnits(draft.grossMinorUnits),
      discount: fromMinorUnits(draft.discountMinorUnits),
      globalDiscountShare: fromMinorUnits(shareMinorUnits),
      discountTotal: fromMinorUnits(draft.discountMinorUnits + shareMinorUnits),
      taxableBase: fromMinorUnits(taxableBaseMinorUnits),
      taxRate: appliedTaxRate,
      taxAmount: fromMinorUnits(lineTaxMinorUnits),
      lineTotal: fromMinorUnits(lineTotalMinorUnits),
    };
  });

  return {
    lines,
    lineCount: lines.length,
    quantityTotal: roundQuantity(items.reduce((total, item) => total + item.quantity, 0)),
    subtotal: fromMinorUnits(subtotalMinorUnits),
    itemDiscountTotal: fromMinorUnits(itemDiscountMinorUnits),
    globalDiscount: fromMinorUnits(globalDiscountMinorUnits),
    discountTotal: fromMinorUnits(itemDiscountMinorUnits + globalDiscountMinorUnits),
    taxRate: appliedTaxRate,
    taxTotal: fromMinorUnits(taxTotalMinorUnits),
    grandTotal: fromMinorUnits(grandTotalMinorUnits),
  };
}

/**
 * Why an action refused to change the cart.
 *
 * Every mutating action reports this instead of throwing, so the POS UI can
 * show a precise message ("This product is not active") without try/catch and
 * without the store ever entering an invalid state.
 */
export type CartActionFailure =
  | "invalid-product"
  | "product-not-active"
  | "invalid-quantity"
  | "invalid-unit-price"
  | "invalid-barcode"
  | "product-not-found"
  | "lookup-failed"
  | "item-not-found"
  | "price-selection-required"
  | "insufficient-batch-stock";

/**
 * Outcome of a mutating cart action.
 *
 * `itemIds` holds every line that was created, changed or removed, which lets
 * the UI highlight or scroll to the affected line. `removed` is `true` when the
 * line was deleted instead of updated (decreasing the last unit removes it).
 */
export type CartActionResult =
  | { ok: true; itemIds: string[]; removed: boolean }
  | { ok: false; reason: CartActionFailure };

/** Optional overrides applied when a product is put into the cart. */
export interface AddProductOptions {
  /** Quantity to add; defaults to `1` and is validated per unit of measure. */
  quantity?: number;
  /** Price override for this line; defaults to `product.sellingPrice`. */
  unitPrice?: number;
  /**
   * Batch row the line was allocated from. `null` means the automatic
   * price selection applied (the backend splits the line across batches with
   * FIFO at the server-confirmed price) — no single batch owns these units
   * on the client, so no `batch_id` is sent to the sale endpoint.
   */
  batchId?: number | null;
  /**
   * Combined units available at this selling price (from price-options).
   * `null`/omitted means the line is not batch-price gated.
   */
  priceAvailableQuantity?: number | null;
  /** Line note printed with the sale, e.g. "no sugar". */
  note?: string | null;
}

/**
 * Result of a barcode lookup.
 *
 * May be value or promise: the mock catalogue answers synchronously, while the
 * Laravel API answers asynchronously. `null`/`undefined` means "not found".
 */
export type BarcodeLookupResult = Product | null | undefined;

/**
 * Injected barcode resolver.
 *
 * The cart store deliberately does not know how products are found. The POS
 * screen passes a resolver backed by cached master data or by
 * `GET /api/products/barcode/{barcode}`, which keeps lookup and mutation from
 * being coupled and keeps API code out of the store.
 */
export type BarcodeLookup = (barcode: string) => BarcodeLookupResult | Promise<BarcodeLookupResult>;

/**
 * Minimal customer reference attached to the running sale (Phase 8).
 *
 * Only identity travels with the cart; the full record stays in the customer
 * store, so an in-progress sale keeps working even if the directory changes
 * mid-sale — the same snapshot idea the cart already uses for products.
 */
export interface CartCustomerRef {
  /** Id of the selected customer. */
  id: number;
  /** Display name captured at selection time (printed on the receipt). */
  name: string;
}

/** Cart state persisted in memory. */
export interface CartState {
  /** Lines of the running sale, in the order they were added. */
  items: CartItem[];
  /** Invoice level discount in currency units (always `>= 0`). */
  globalDiscount: number;
  /** VAT percentage applied to every line (always `0..100`). */
  taxRate: number;
  /** Customer attached to the sale; `null` means a walk-in sale. */
  customerId: number | null;
  /** Name snapshot of the attached customer; `null` for walk-in. */
  customerName: string | null;
}

/**
 * Everything that can change the cart.
 *
 * Actions that target a line return a `CartActionResult`; numeric setters return
 * the value that was actually stored after validation and clamping.
 */
export interface CartActions {
  /** Adds a product that was already resolved by the caller. */
  addProduct(product: Product | null | undefined, options?: AddProductOptions): CartActionResult;
  /** Resolves a scanned barcode, then adds the resulting product. */
  addProductByBarcode(barcode: string, lookup: BarcodeLookup): Promise<CartActionResult>;
  /** Removes one line. */
  removeItem(itemId: string): CartActionResult;
  /** Removes every line of a product (a product may span several lines). */
  removeProduct(productId: number): CartActionResult;
  /** Adds one quantity step (per unit) or the step given by the caller. */
  increaseQuantity(itemId: string, step?: number): CartActionResult;
  /** Subtracts one quantity step; reaching zero removes the line. */
  decreaseQuantity(itemId: string, step?: number): CartActionResult;
  /** Sets an absolute quantity; zero or less removes the line. */
  setQuantity(itemId: string, quantity: number): CartActionResult;
  /** Sets the item wise discount and returns the clamped amount applied. */
  setItemDiscount(itemId: string, discount: number): number;
  /** Sets the invoice level discount and returns the clamped amount applied. */
  setGlobalDiscount(discount: number): number;
  /** Sets the VAT percentage and returns the clamped rate applied. */
  setTaxRate(taxRate: number): number;
  /**
   * Attaches a customer to the sale (Phase 8); `null` returns to walk-in.
   * Stores only an `{ id, name }` snapshot so the receipt and a future sale
   * payload stay correct even if the directory changes mid-sale.
   */
  setCustomer(customer: CartCustomerRef | null): void;
  /** Empties the cart and returns it to its initial state. */
  clearCart(): void;
}

/** Shape of the global cart store: state plus actions in one object. */
export type CartStore = CartState & CartActions;

/** Initial cart: empty, no discounts, no tax, walk-in until configured. */
const INITIAL_CART_STATE: CartState = {
  items: [],
  globalDiscount: 0,
  taxRate: 0,
  customerId: null,
  customerName: null,
};

/**
 * Global cart store.
 *
 * The store owns the cart and nothing else: product master data stays in the
 * catalogue/services layer, lookup stays in the caller, and totals are derived
 * through `calculateCartTotals`. That keeps the running sale independent of the
 * mock data and makes the later Laravel integration a caller-side change.
 */
export const useCartStore = create<CartStore>()((set, get) => ({
  ...INITIAL_CART_STATE,

  /**
   * Add product.
   *
   * Validates the resolved product, copies a line snapshot, then either merges
   * into an existing line with the same product and unit price (the usual POS
   * behaviour: scanning twice increases the quantity) or appends a new line.
   * Quantity, price and product are all checked, so an invalid payload can never
   * create a corrupt line.
   */
  addProduct(product, options = {}) {
    const snapshot = toCartItemProduct(product);

    if (!snapshot) {
      return { ok: false, reason: "invalid-product" };
    }

    if (snapshot.status !== "active") {
      return { ok: false, reason: "product-not-active" };
    }

    const quantity = normalizeQuantity(options.quantity ?? DEFAULT_QUANTITY, snapshot.unit);

    if (quantity === null) {
      return { ok: false, reason: "invalid-quantity" };
    }

    const unitPrice = normalizeUnitPrice(options.unitPrice ?? snapshot.sellingPrice);

    if (unitPrice === null) {
      return { ok: false, reason: "invalid-unit-price" };
    }

    const batchId = options.batchId ?? null;
    const priceAvailableQuantity =
      options.priceAvailableQuantity === undefined ? null : options.priceAvailableQuantity;

    const note = typeof options.note === "string" && options.note.trim() ? options.note.trim() : null;
    const existing = get().items.find(
      (item) => item.productId === snapshot.id && item.unitPrice === unitPrice,
    );

    if (existing) {
      const mergedQuantity = normalizeQuantity(existing.quantity + quantity, existing.product.unit);

      if (mergedQuantity === null) {
        return { ok: false, reason: "invalid-quantity" };
      }

      const available = existing.priceAvailableQuantity ?? priceAvailableQuantity;
      if (available !== null && mergedQuantity > available) {
        return { ok: false, reason: "insufficient-batch-stock" };
      }

      set((state) => ({
        items: state.items.map((item) =>
          item.id === existing.id
            ? {
                ...item,
                quantity: mergedQuantity,
                discount: clampLineDiscount(item, mergedQuantity),
                note: note ?? item.note,
              }
            : item,
        ),
      }));

      return { ok: true, itemIds: [existing.id], removed: false };
    }

    if (priceAvailableQuantity !== null && quantity > priceAvailableQuantity) {
      return { ok: false, reason: "insufficient-batch-stock" };
    }

    const item: CartItem = {
      id: createItemId(),
      productId: snapshot.id,
      product: snapshot,
      quantity,
      unitPrice,
      batchId,
      priceAvailableQuantity,
      discount: 0,
      note,
    };

    set((state) => ({ items: [...state.items, item] }));

    return { ok: true, itemIds: [item.id], removed: false };
  },

  /**
   * Barcode scanner entry point.
   *
   * Normalises the scanned code, asks the injected resolver for a product and
   * delegates to `addProduct`. A crashing resolver is reported as
   * `lookup-failed` instead of breaking the scan loop, and an unknown code as
   * `product-not-found`. Async, so the same call works with mock data and with
   * the Laravel API.
   */
  async addProductByBarcode(barcode, lookup) {
    const normalizedBarcode = typeof barcode === "string" ? barcode.trim() : "";

    if (!normalizedBarcode) {
      return { ok: false, reason: "invalid-barcode" };
    }

    let product: BarcodeLookupResult;

    try {
      product = await lookup(normalizedBarcode);
    } catch {
      return { ok: false, reason: "lookup-failed" };
    }

    if (!product) {
      return { ok: false, reason: "product-not-found" };
    }

    return get().addProduct(product);
  },

  /** Removes a single line by its line id. */
  removeItem(itemId) {
    if (!get().items.some((item) => item.id === itemId)) {
      return { ok: false, reason: "item-not-found" };
    }

    set((state) => ({ items: state.items.filter((item) => item.id !== itemId) }));

    return { ok: true, itemIds: [itemId], removed: true };
  },

  /** Removes every line of a product, e.g. "void this item" from the till. */
  removeProduct(productId) {
    const removedItemIds = get()
      .items.filter((item) => item.productId === productId)
      .map((item) => item.id);

    if (removedItemIds.length === 0) {
      return { ok: false, reason: "item-not-found" };
    }

    set((state) => ({ items: state.items.filter((item) => item.productId !== productId) }));

    return { ok: true, itemIds: removedItemIds, removed: true };
  },

  /**
   * Increase quantity.
   *
   * The step defaults to the unit's natural step (`1` for piece, `0.5 kg` for
   * kilogram) and the result is normalised, so the line is never pushed past the
   * per line ceiling. The stored line discount is re-clamped because the line is
   * worth more than before, never less.
   */
  increaseQuantity(itemId, step) {
    const item = get().items.find((candidate) => candidate.id === itemId);

    if (!item) {
      return { ok: false, reason: "item-not-found" };
    }

    const increment = step ?? quantityStep(item.product.unit);

    if (!Number.isFinite(increment) || increment <= 0) {
      return { ok: false, reason: "invalid-quantity" };
    }

    const quantity = normalizeQuantity(item.quantity + increment, item.product.unit);

    if (quantity === null) {
      return { ok: false, reason: "invalid-quantity" };
    }

    if (item.priceAvailableQuantity !== null && quantity > item.priceAvailableQuantity) {
      return { ok: false, reason: "insufficient-batch-stock" };
    }

    set((state) => ({
      items: state.items.map((candidate) =>
        candidate.id === itemId ? { ...candidate, quantity } : candidate,
      ),
    }));

    return { ok: true, itemIds: [itemId], removed: false };
  },

  /**
   * Decrease quantity.
   *
   * Quantity can never drop below zero: when the step takes the line to zero or
   * below, the line is removed and the result reports `removed: true`, which is
   * what a cashier expects when the last unit is removed.
   */
  decreaseQuantity(itemId, step) {
    const item = get().items.find((candidate) => candidate.id === itemId);

    if (!item) {
      return { ok: false, reason: "item-not-found" };
    }

    const decrement = step ?? quantityStep(item.product.unit);

    if (!Number.isFinite(decrement) || decrement <= 0) {
      return { ok: false, reason: "invalid-quantity" };
    }

    const quantity = roundQuantity(item.quantity - decrement);

    if (quantity <= 0) {
      set((state) => ({ items: state.items.filter((candidate) => candidate.id !== itemId) }));
      return { ok: true, itemIds: [itemId], removed: true };
    }

    const normalized = normalizeQuantity(quantity, item.product.unit);

    if (normalized === null) {
      return { ok: false, reason: "invalid-quantity" };
    }

    set((state) => ({
      items: state.items.map((candidate) =>
        candidate.id === itemId
          ? { ...candidate, quantity: normalized, discount: clampLineDiscount(candidate, normalized) }
          : candidate,
      ),
    }));

    return { ok: true, itemIds: [itemId], removed: false };
  },

  /**
   * Update quantity to an absolute value.
   *
   * Non numeric input is rejected, while `0` or a negative number clears the
   * line (the cashier's "empty this line" shortcut) so no negative quantity can
   * ever be stored. A shrinking line has its discount re-clamped to stay valid.
   */
  setQuantity(itemId, quantity) {
    const item = get().items.find((candidate) => candidate.id === itemId);

    if (!item) {
      return { ok: false, reason: "item-not-found" };
    }

    if (!Number.isFinite(quantity)) {
      return { ok: false, reason: "invalid-quantity" };
    }

    const normalized = normalizeQuantity(quantity, item.product.unit);

    if (normalized === null) {
      set((state) => ({ items: state.items.filter((candidate) => candidate.id !== itemId) }));
      return { ok: true, itemIds: [itemId], removed: true };
    }

    if (item.priceAvailableQuantity !== null && normalized > item.priceAvailableQuantity) {
      return { ok: false, reason: "insufficient-batch-stock" };
    }

    set((state) => ({
      items: state.items.map((candidate) =>
        candidate.id === itemId
          ? { ...candidate, quantity: normalized, discount: clampLineDiscount(candidate, normalized) }
          : candidate,
      ),
    }));

    return { ok: true, itemIds: [itemId], removed: false };
  },

  /**
   * Item wise discount.
   *
   * Stored as an absolute amount in currency units and clamped to
   * `[0, quantity * unitPrice]`, so a line discount can never be negative and
   * can never be larger than the line itself. Returns the amount actually
   * stored, which lets the UI show the clamped value immediately.
   */
  setItemDiscount(itemId, discount) {
    const item = get().items.find((candidate) => candidate.id === itemId);

    if (!item) {
      return 0;
    }

    const appliedDiscount = clamp(
      roundMoney(discount),
      0,
      roundMoney(item.quantity * item.unitPrice),
    );

    if (appliedDiscount === item.discount) {
      return appliedDiscount;
    }

    set((state) => ({
      items: state.items.map((candidate) =>
        candidate.id === itemId ? { ...candidate, discount: appliedDiscount } : candidate,
      ),
    }));

    return appliedDiscount;
  },

  /**
   * Global discount.
   *
   * Clamped to `[0, subtotal - item discounts]`: the invoice can be discounted to
   * zero, never below it. `calculateCartTotals` re-clamps on read as well, so a
   * stored value that became too large after a line was removed can never leak a
   * negative total onto the screen.
   */
  setGlobalDiscount(discount) {
    const { items, taxRate } = get();
    const { subtotal, itemDiscountTotal } = calculateCartTotals(items, 0, taxRate);
    const appliedDiscount = clamp(roundMoney(discount), 0, roundMoney(subtotal - itemDiscountTotal));

    set({ globalDiscount: appliedDiscount });

    return appliedDiscount;
  },

  /**
   * Tax / VAT rate.
   *
   * Kept as a percentage (`15` = 15%) because that is how VAT is configured and
   * printed. Clamped to `0..100` and rounded to two decimals so totals stay
   * stable. Per product rates will arrive from Laravel later; the cart keeps one
   * invoice level rate for now.
   */
  setTaxRate(taxRate) {
    const appliedTaxRate = clamp(roundTo(Number.isFinite(taxRate) ? taxRate : 0, 2), 0, MAX_TAX_RATE);

    set({ taxRate: appliedTaxRate });

    return appliedTaxRate;
  },

  /**
   * Customer attachment (Phase 8).
   *
   * Written by the POS customer selector, read by the receipt builder (before
   * `clearCart()`) and, later, by the sale payload posted to Laravel.
   * `clearCart()` resets both fields through `INITIAL_CART_STATE`, so the
   * next sale always starts as a walk-in sale.
   */
  setCustomer(customer) {
    set(
      customer === null
        ? { customerId: null, customerName: null }
        : { customerId: customer.id, customerName: customer.name },
    );
  },

  /**
   * Clears the cart.
   *
   * Resets lines, discounts and the tax rate to the initial state, so the next
   * sale starts from a clean, predictable baseline.
   */
  clearCart() {
    set(INITIAL_CART_STATE);
  },
}));

/** Selects the cart lines; stable reference, so it is render friendly. */
export const selectCartItems = (state: CartStore): CartItem[] => state.items;

/** Selects the invoice level discount amount. */
export const selectGlobalDiscount = (state: CartStore): number => state.globalDiscount;

/** Selects the VAT percentage. */
export const selectTaxRate = (state: CartStore): number => state.taxRate;

/** Selects the attached customer id; `null` for a walk-in sale. */
export const selectCustomerId = (state: CartStore): number | null => state.customerId;

/** Selects the attached customer's name snapshot; `null` for walk-in. */
export const selectCartCustomerName = (state: CartStore): string | null =>
  state.customerName;

/**
 * Selects the calculated totals.
 *
 * Only for non render reads (`useCartStore.getState()`), because it builds a new
 * object on every call. Components must use `useCartTotals()`, which memoises on
 * the raw state values and therefore keeps `useSyncExternalStore` happy.
 */
export const selectCartTotals = (state: CartStore): CartTotals =>
  calculateCartTotals(state.items, state.globalDiscount, state.taxRate);

/** Subscribes to the cart lines. */
export function useCartItems(): CartItem[] {
  return useCartStore(selectCartItems);
}

/**
 * Subscribes to the totals.
 *
 * The pure calculation runs again on every cart change, never on unrelated
 * re-renders, and the result is memoised so components can safely pass it down
 * as a prop without triggering extra renders.
 */
export function useCartTotals(): CartTotals {
  const items = useCartStore(selectCartItems);
  const globalDiscount = useCartStore(selectGlobalDiscount);
  const taxRate = useCartStore(selectTaxRate);

  return useMemo(
    () => calculateCartTotals(items, globalDiscount, taxRate),
    [items, globalDiscount, taxRate],
  );
}
