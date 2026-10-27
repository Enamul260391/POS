import { useCartStore } from "@/stores/useCartStore";
import { useCatalogueStore } from "@/stores/useProductStore";
import type { CartItem, CartItemProduct } from "@/types/cart";
import type { Product } from "@/types/product";

/**
 * Recoverable POS work state (cart + last working page), persisted so a refresh
 * or an idle sign-out does not lose an in-progress sale, and so the same cashier
 * resumes where they left off after signing back in.
 *
 * Storage strategy — why `sessionStorage`, never `localStorage`:
 *   - `sessionStorage` is scoped to the individual browser tab and survives a
 *     reload of that tab, which is exactly the "restore my work" requirement
 *     while still keeping two tabs fully independent. A shared `localStorage`
 *     snapshot would let one account's tab see — and restore — another account's
 *     cart, which is precisely the leak this design refuses.
 *   - Isolation is enforced a second time by the **key**: every record is filed
 *     under `userId.businessId`, and is only ever read back when the signing-in
 *     principal matches that exact pair. A Platform Owner (`businessId` null) can
 *     therefore never inherit a Business User's cart, and Business User A can
 *     never see Business User B's.
 *
 * What is stored is the minimum needed to resume: the cart lines (product id,
 * snapshot, quantity, line price/discount/note), the invoice discount and VAT
 * rate, the attached customer reference, and the route. No bearer token, no
 * password and no card data is ever written here — the token lives only in
 * `@/lib/auth/token`.
 *
 * Trust: persisted totals are never authoritative. Totals are always recomputed
 * from the line data (see `calculateCartTotals`), and on restore each line is
 * revalidated against the current, tenant-scoped catalogue when it is available,
 * so a price/stock change or a deleted product is reflected rather than trusted.
 */

/** Storage key prefix; the tenant/user suffix is appended by {@link workStateKey}. */
const WORK_STATE_PREFIX = "pos.work.";

/** The subset of cart state that is worth recovering. */
export interface PersistedCart {
  items: CartItem[];
  globalDiscount: number;
  taxRate: number;
  customerId: number | null;
  customerName: string | null;
}

/** A saved working session, tagged with the identity it belongs to. */
export interface PersistedWorkState extends PersistedCart {
  /** Owner of this work state; used to gate restoration to the same principal. */
  userId: number;
  /** Tenant the cart belongs to; `null` for the Platform Owner. */
  businessId: number | null;
  /** Last working route (validated on restore before it is ever honoured). */
  route: string;
  /** When the snapshot was written (informational / diagnostics). */
  savedAt: number;
}

/** Whether the tab-scoped storage API is reachable (never during SSR). */
function canUseStorage(): boolean {
  return typeof window !== "undefined" && typeof window.sessionStorage !== "undefined";
}

/** Storage key uniquely identifying one principal's work state. */
export function workStateKey(userId: number, businessId: number | null): string {
  return `${WORK_STATE_PREFIX}${userId}.${businessId ?? "platform"}`;
}

/**
 * Persists the current cart and route for a principal.
 *
 * Called live on every cart change (so a refresh or idle sign-out never loses
 * work) and once more at idle sign-out to capture the final route. Overwrites
 * the previous snapshot for this exact principal.
 */
export function saveWorkState(
  userId: number,
  businessId: number | null,
  route: string,
): void {
  if (!canUseStorage()) {
    return;
  }
  const { items, globalDiscount, taxRate, customerId, customerName } =
    useCartStore.getState();
  const snapshot: PersistedWorkState = {
    userId,
    businessId,
    route,
    items,
    globalDiscount,
    taxRate,
    customerId,
    customerName,
    savedAt: Date.now(),
  };
  try {
    window.sessionStorage.setItem(workStateKey(userId, businessId), JSON.stringify(snapshot));
  } catch {
    // Storage unavailable (private mode / quota): work simply is not recoverable.
  }
}

/**
 * Reads a principal's saved work state, or `null` when there is none or it is
 * unreadable. The caller is responsible for only asking for the identity that is
 * actually signing in — that match is what keeps tenants and users isolated.
 */
export function readWorkState(
  userId: number,
  businessId: number | null,
): PersistedWorkState | null {
  if (!canUseStorage()) {
    return null;
  }
  try {
    const raw = window.sessionStorage.getItem(workStateKey(userId, businessId));
    if (raw === null) {
      return null;
    }
    const parsed = JSON.parse(raw) as PersistedWorkState;
    // Defensive: never honour a record whose identity tag does not match the key
    // it was stored under, even if something tampered with storage.
    if (
      typeof parsed !== "object" ||
      parsed === null ||
      parsed.userId !== userId ||
      parsed.businessId !== businessId ||
      !Array.isArray(parsed.items)
    ) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

/** Removes a principal's saved work state (explicit logout / completed sale). */
export function clearWorkState(userId: number, businessId: number | null): void {
  if (!canUseStorage()) {
    return;
  }
  try {
    window.sessionStorage.removeItem(workStateKey(userId, businessId));
  } catch {
    // Nothing to do — see `saveWorkState`.
  }
}

/** Rebuilds a validated product snapshot, or `null` when the value is unusable. */
function toSnapshot(product: Product | CartItemProduct): CartItemProduct | null {
  if (typeof product !== "object" || product === null) {
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
  if (product.status !== "active") {
    // A persisted inactive line is never restored: the POS must not sell it.
    return null;
  }
  return {
    id: product.id,
    name: product.name,
    sku: typeof product.sku === "string" ? product.sku : "",
    barcode: typeof product.barcode === "string" && product.barcode.trim() ? product.barcode : null,
    unit: product.unit,
    image: typeof product.image === "string" && product.image.trim() ? product.image : null,
    status: product.status,
  };
}

/**
 * Restores a principal's saved cart into the live cart store.
 *
 * Revalidation rules (never trust a persisted total or a stale product):
 *   - Each line's product is looked up in the current, tenant-scoped catalogue
 *     when that catalogue has loaded, so the *current* price/stock is used. If
 *     the catalogue has not loaded yet, the persisted snapshot (itself already
 *     validated on the way in) is used, and the authoritative backend still
 *     rejects anything invalid at checkout.
 *   - Products that are missing from a loaded catalogue, or present but inactive,
 *     are dropped rather than restored.
 *   - Totals are not restored at all: they are always derived from the lines.
 *
 * Returns the lines that were restored so the caller can explain anything that
 * could not be recovered. Restoration is skipped entirely when the cart already
 * holds items, so it can never clobber a live sale or duplicate lines.
 */
export function restoreWorkState(state: PersistedWorkState): CartItem[] {
  const store = useCartStore.getState();
  if (store.items.length > 0) {
    // A live sale is already in progress; do not merge or overwrite it.
    return store.items;
  }

  const catalogue = useCatalogueStore.getState().products;
  const catalogueLoaded = catalogue.length > 0;
  const restored: CartItem[] = [];

  for (const line of state.items) {
    const current = catalogueLoaded
      ? catalogue.find((product) => product.id === line.productId)
      : undefined;
    // Deleted / no-longer-available in a loaded catalogue → skip safely.
    if (catalogueLoaded && current === undefined) {
      continue;
    }
    const snapshot = toSnapshot(current ?? line.product);
    if (snapshot === null) {
      continue;
    }
    const result = store.addProduct(snapshot as unknown as Product, {
      quantity: line.quantity,
      unitPrice: line.unitPrice,
      batchId: typeof line.batchId === "number" ? line.batchId : null,
      priceAvailableQuantity:
        typeof line.priceAvailableQuantity === "number" ? line.priceAvailableQuantity : null,
      note: line.note,
    });
    if (result.ok) {
      const restoredLine = useCartStore
        .getState()
        .items.find(
          (item) => item.productId === line.productId && item.unitPrice === line.unitPrice,
        );
      if (restoredLine) {
        restored.push(restoredLine);
      }
    }
  }

  if (restored.length > 0) {
    store.setGlobalDiscount(state.globalDiscount);
    store.setTaxRate(state.taxRate);
    if (state.customerId !== null && state.customerName !== null) {
      store.setCustomer({ id: state.customerId, name: state.customerName });
    }
  }

  return restored;
}

