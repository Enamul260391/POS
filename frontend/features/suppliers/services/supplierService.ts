import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Supplier, SupplierPayload } from "@/types/supplier";

import { toSupplier, toSuppliers } from "../lib/suppliers";

/**
 * Supplier service — the only module that talks to `/suppliers`.
 *
 * Tenancy: no request carries a `business_id`. Laravel resolves the owning
 * business from the bearer token, so another tenant's suppliers are simply not
 * reachable.
 */

/** Query parameters accepted by `GET /suppliers` (`IndexSupplierRequest`). */
export interface SupplierFilters {
  /** Free text over name, phone, email and address. */
  search?: string;
  status?: "active" | "inactive";
  page?: number;
  perPage?: number;
  sort?: "name" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /suppliers` (`StoreSupplierRequest`). */
export interface SupplierInput {
  name: string;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  openingBalance?: number | null;
  status?: "active" | "inactive";
}

/** Body of `PUT|PATCH /suppliers/{id}` (`UpdateSupplierRequest`). */
export type SupplierUpdateInput = Partial<SupplierInput>;

/** Maps the frontend filter object onto the query string. */
function toQuery(filters: SupplierFilters | undefined): ApiRequestConfig["params"] {
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
function toSupplierBody(
  input: SupplierInput | SupplierUpdateInput,
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

/** `GET /suppliers` — one page, filtered and sorted server-side. */
export async function listSuppliers(
  filters?: SupplierFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Supplier>> {
  const data = await apiList<SupplierPayload>("/suppliers", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toSuppliers(data.items), pagination: data.pagination };
}

/** `GET /suppliers/{id}` — one supplier. */
export async function getSupplier(
  id: number,
  config?: ApiRequestConfig,
): Promise<Supplier> {
  const payload = await apiGet<SupplierPayload>(`/suppliers/${id}`, {
    signal: config?.signal,
  });
  return toSupplier(payload);
}

/** `POST /suppliers` — creates a supplier. */
export async function createSupplier(input: SupplierInput): Promise<Supplier> {
  const payload = await apiPost<SupplierPayload>("/suppliers", toSupplierBody(input));
  return toSupplier(payload);
}

/** `PUT|PATCH /suppliers/{id}` — partial update of the supplied fields. */
export async function updateSupplier(
  id: number,
  input: SupplierUpdateInput,
): Promise<Supplier> {
  const payload = await apiPatch<SupplierPayload>(
    `/suppliers/${id}`,
    toSupplierBody(input),
  );
  return toSupplier(payload);
}

/**
 * `DELETE /suppliers/{id}` — soft-deletes a supplier.
 *
 * A supplier that already appears on a purchase is refused by the service layer
 * with a `409`; the shared error handling surfaces that message verbatim.
 */
export async function deleteSupplier(id: number): Promise<void> {
  await apiDelete<null>(`/suppliers/${id}`);
}