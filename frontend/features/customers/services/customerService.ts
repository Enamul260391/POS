import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Customer, CustomerPayload } from "@/types/customer";

import { toCustomer, toCustomers } from "../lib/customerMapper";

/**
 * Customer service — the only module that talks to `/customers`.
 *
 * Tenancy: no request carries a `business_id`. Laravel resolves the owning
 * business from the bearer token, so another tenant's customers are simply not
 * reachable.
 */

/** Query parameters accepted by `GET /customers` (`IndexCustomerRequest`). */
export interface CustomerFilters {
  /** Free text over name, phone, email and address. */
  search?: string;
  status?: "active" | "inactive";
  page?: number;
  perPage?: number;
  sort?: "name" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /customers` (`StoreCustomerRequest`). */
export interface CustomerInput {
  name: string;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  openingBalance?: number | null;
  status?: "active" | "inactive";
}

/** Body of `PUT|PATCH /customers/{id}` (`UpdateCustomerRequest`). */
export type CustomerUpdateInput = Partial<CustomerInput>;

/** Maps the frontend filter object onto the query string. */
function toQuery(
  filters: CustomerFilters | undefined,
): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    status: filters.status,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/** Shared body builder, so create and update can never drift apart. */
function toCustomerBody(
  input: CustomerInput | CustomerUpdateInput,
): Record<string, unknown> {
  return {
    name: input.name?.trim() ?? "",
    phone: input.phone?.trim() || null,
    email: input.email?.trim() || null,
    address: input.address?.trim() || null,
    opening_balance: input.openingBalance ?? null,
    status: input.status ?? "active",
  };
}

/** `GET /customers` — one page, filtered and sorted server-side. */
export async function listCustomers(
  filters?: CustomerFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Customer>> {
  const data = await apiList<CustomerPayload>("/customers", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toCustomers(data.items), pagination: data.pagination };
}

/**
 * `GET /customers` for the POS picker.
 *
 * The selector searches as the cashier types, so it goes through the backend's
 * `search` parameter (indexed SQL `LIKE`) instead of downloading the directory
 * and filtering it in the browser. An empty query returns the first page, which
 * is what an untouched picker shows.
 */
export async function searchCustomers(
  search: string,
  config?: ApiRequestConfig,
): Promise<Customer[]> {
  const data = await apiList<CustomerPayload>("/customers", {
    params: { search: search.trim(), status: "active", per_page: 20, page: 1 },
    signal: config?.signal,
  });

  return toCustomers(data.items);
}

/** `GET /customers/{id}` — one customer. */
export async function getCustomer(
  id: number,
  config?: ApiRequestConfig,
): Promise<Customer> {
  const payload = await apiGet<CustomerPayload>(`/customers/${id}`, {
    signal: config?.signal,
  });
  return toCustomer(payload);
}

/** `POST /customers` — creates a customer. */
export async function createCustomer(input: CustomerInput): Promise<Customer> {
  const payload = await apiPost<CustomerPayload>("/customers", toCustomerBody(input));
  return toCustomer(payload);
}

/** `PUT|PATCH /customers/{id}` — partial update of the supplied fields. */
export async function updateCustomer(
  id: number,
  input: CustomerUpdateInput,
): Promise<Customer> {
  const payload = await apiPatch<CustomerPayload>(
    `/customers/${id}`,
    toCustomerBody(input),
  );
  return toCustomer(payload);
}

/**
 * `DELETE /customers/{id}` — soft-deletes a customer.
 *
 * A customer that already appears on a sale is refused by the service layer
 * with a `409`; that message is surfaced verbatim.
 */
export async function deleteCustomer(id: number): Promise<void> {
  await apiDelete<null>(`/customers/${id}`);
}