import type { Product } from "@/types/product";

/**
 * Client side cart types.
 *
 * The cart only exists in the frontend: it is never sent to Laravel as-is.
 * When a sale is completed, the cart is converted into a sale payload and the
 * API answers with a `Sale` (`@/types/sale`).
 *
 * A cart line deliberately keeps cart specific data (quantity, price override,
 * discount, note) apart from the product data it points to.
 */

/**
 * Product data captured on a cart line.
 *
 * Only the fields the cashier needs to render and total a line are copied.
 * Keeping a snapshot means an in-progress sale stays valid and correctly
 * priced even if the catalogue changes or the network drops mid-sale.
 */
export type CartItemProduct = Pick<
  Product,
  | "id"
  | "name"
  | "sku"
  | "barcode"
  | "unit"
  | "image"
  | "status"
>;

export interface CartItem {
  /**
   * Client generated line id. A product may appear on several lines
   * (different price overrides or notes), so the line needs its own id.
   */
  id: string;
  /** Id of the product being sold. */
  productId: number;
  /** Product snapshot taken when the line was created. */
  product: CartItemProduct;
  /**
   * Quantity in `product.unit`. Fractional values are allowed for weight
   * based units such as `kilogram` or `liter`.
   */
  quantity: number;
  /** Price used for this line: set from batch pricing when added unless overridden. */
  unitPrice: number;
  /**
   * Batch row the line was allocated from, when the sale needs an explicit
   * batch selection (multiple distinct selling prices). `null` means the
   * automatic price selection applied: the backend splits the line across
   * batches with FIFO at the server-confirmed price, so no single batch owns
   * those units on the client.
   */
  batchId: number | null;
  /**
   * Combined units still sellable at this line's selling price, taken from
   * the price-options response when the line was added. `null` means the
   * product is legacy (no batch history) or untracked, so only ledger stock
   * applies.
   */
  priceAvailableQuantity: number | null;
  /** Absolute line discount in currency units. */
  discount: number;
  /** Free text note for the line, e.g. "no sugar" or "gift wrap". */
  note: string | null;
}
