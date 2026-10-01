import { z } from "zod";

import type { Customer, CustomerStatus } from "@/types/customer";

/**
 * Form contract of the Add/Edit customer dialog (Phase 8, Phase 25).
 *
 * One schema factory serves validation and typing.
 *
 * Phase 25 changes, driven by `StoreCustomerRequest` / `UpdateCustomerRequest`:
 *   - the `notes` textarea is gone: the Phase 16 `customers` table has no
 *     `notes` column, so a field that could not be persisted would only lie to
 *     the user;
 *   - phone/email uniqueness is no longer checked here. The schema has no unique
 *     index behind it (`StoreCustomerRequest` documents that duplicates are
 *     allowed exactly as the database allows them), and the client had no
 *     database to be right about.
 *
 * Financial fields stay out, as always: sales and payments own money, exactly
 * like the inventory module owns stock.
 */

/** Select options for the status field (form and filters share them). */
export const STATUS_OPTIONS: readonly { value: CustomerStatus; label: string }[] = [
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

/** Loose phone shape: digits, spaces and dashes with an optional leading `+`. */
const PHONE_PATTERN = /^\+?[\d\s-]{6,20}$/;

const customerFields = {
  name: z
    .string()
    .trim()
    .min(1, "Name is required")
    .max(100, "Name is too long"),
  phone: z
    .string()
    .trim()
    .max(20, "Phone is too long")
    .refine((value) => value === "" || PHONE_PATTERN.test(value), "Enter a valid phone number"),
  email: z
    .string()
    .trim()
    .max(100, "Email is too long")
    .refine(
      (value) => value === "" || z.string().email().safeParse(value).success,
      "Enter a valid email address",
    ),
  address: z.string().trim().max(1000, "Address is too long"),
  status: z.enum(["active", "inactive"]),
};

/** Valid shape of the customer form. */
export const baseCustomerSchema = z.object(customerFields);

export type CustomerFormValues = z.infer<typeof baseCustomerSchema>;

/**
 * Blank state for "Add customer".
 *
 * Optional fields start as `""`: the inputs render empty and the service maps
 * them back to `null` on save, keeping the `Customer` type honest.
 */
export const EMPTY_CUSTOMER_VALUES: CustomerFormValues = {
  name: "",
  phone: "",
  email: "",
  address: "",
  status: "active",
};

/** Maps an existing customer into form state (edit mode). */
export function toCustomerFormValues(customer: Customer): CustomerFormValues {
  return {
    name: customer.name,
    phone: customer.phone ?? "",
    email: customer.email ?? "",
    address: customer.address ?? "",
    status: customer.status,
  };
}