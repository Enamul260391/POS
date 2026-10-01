import { toMoney, toText } from "@/lib/api/values";
import type { Customer, CustomerPayload, CustomerStatus } from "@/types/customer";

/**
 * `CustomerResource` → `Customer`.
 *
 * Money arrives as a decimal string (`opening_balance`) and is converted once,
 * here. `status` is narrowed to the union the UI switches on, with anything
 * unknown treated as `inactive` — the safe default, because an unrecognised
 * customer must not appear in the checkout picker.
 */
export function toCustomer(payload: CustomerPayload): Customer {
  return {
    id: payload.id,
    name: payload.name,
    phone: toText(payload.phone),
    email: toText(payload.email),
    address: toText(payload.address),
    openingBalance: toMoney(payload.opening_balance),
    status: toCustomerStatus(payload.status),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of customers. */
export function toCustomers(payloads: readonly CustomerPayload[]): Customer[] {
  return payloads.map(toCustomer);
}

/** Narrows the API's `status` string. */
export function toCustomerStatus(value: string): CustomerStatus {
  return value === "active" ? "active" : "inactive";
}