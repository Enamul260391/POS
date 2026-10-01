import type { Customer, CustomerStatus } from "@/types/customer";

/**
 * Query helpers for the customer screens and the POS selector (Phase 8).
 *
 * Owns the status filter rule so screen and selector components stay
 * presentational.
 *
 * Phase 25: the free-text search is gone from this module. The API applies it in
 * SQL (`IndexCustomerRequest::search`), so both the `/customers` table and the
 * POS picker now send the term to the backend instead of filtering a
 * downloaded directory in the browser — which matters most in the picker, where
 * the customer types a partial name at the till.
 */

/** Status filter options for the customer table, preceded by "All". */
export const STATUS_FILTER_OPTIONS: readonly {
  value: "all" | CustomerStatus;
  label: string;
}[] = [
  { value: "all", label: "All statuses" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

/** Current filter state of the customer list. */
export interface CustomerTableFilters {
  /** Status filter; `"all"` disables it. */
  status: "all" | CustomerStatus;
}

/**
 * Applies the status filter to a page the API already searched.
 *
 * The picker fixes the status to `active`, which is exactly the rule the
 * `Customer.status` doc comment promises.
 */
export function filterCustomers(
  customers: readonly Customer[],
  filters: CustomerTableFilters,
): Customer[] {
  if (filters.status === "all") {
    return [...customers];
  }
  return customers.filter((customer) => customer.status === filters.status);
}