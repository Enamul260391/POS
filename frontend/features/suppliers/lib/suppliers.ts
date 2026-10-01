import { toMoney, toText } from "@/lib/api/values";
import type { Supplier, SupplierPayload, SupplierStatus } from "@/types/supplier";

/**
 * `SupplierResource` → `Supplier`.
 *
 * Money arrives as a decimal string (`opening_balance`) and is converted once,
 * here. `status` is narrowed to the union the UI switches on, with anything
 * unknown treated as `inactive` — the safe default, because an unrecognised
 * supplier must not appear in the purchase picker.
 */
export function toSupplier(payload: SupplierPayload): Supplier {
  return {
    id: payload.id,
    name: payload.name,
    phone: toText(payload.phone),
    email: toText(payload.email),
    address: toText(payload.address),
    openingBalance: toMoney(payload.opening_balance),
    status: toSupplierStatus(payload.status),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of suppliers. */
export function toSuppliers(payloads: readonly SupplierPayload[]): Supplier[] {
  return payloads.map(toSupplier);
}

/** Narrows the API's `status` string. */
export function toSupplierStatus(value: string): SupplierStatus {
  return value === "active" ? "active" : "inactive";
}

/**
 * Presentation helpers for the supplier screens (Phase 25).
 *
 * Phase 9 filtered the whole directory in the browser with `filterSuppliers`.
 * That is gone: `GET /suppliers` now takes the search term as a query parameter
 * and filters in SQL, so the list on screen is already exactly what matched and
 * re-filtering it in memory would only disagree with the server (and silently
 * hide rows the backend paginated in).
 */

/** Selects the suppliers a purchase may be raised against. */
export function activeSuppliers(suppliers: readonly Supplier[]): Supplier[] {
  return suppliers.filter((supplier) => supplier.status === "active");
}

/** `true` when the supplier can be picked on a purchase order. */
export function isSelectable(supplier: Supplier): boolean {
  return supplier.status === "active";
}