import { apiGet, apiList, apiPost, apiPatch, apiDelete } from "@/lib/api/request";
import type { Business, BusinessFormValues, BusinessPayload, BusinessAdminFormValues, BusinessAdminResponse } from "@/types/platform";
import type { PaginatedData } from "@/types/api";

/** Query filters for the platform business list. */
export interface BusinessFilters {
  search?: string;
  status?: string;
  sort?: string;
  direction?: "asc" | "desc";
  perPage?: number;
}

/** Normalises a backend `BusinessPayload` into the frontend `Business` shape. */
function toBusiness(payload: BusinessPayload): Business {
  return {
    id: payload.id,
    name: payload.name,
    phone: payload.phone,
    email: payload.email,
    address: payload.address,
    logo: payload.logo,
    currency: payload.currency,
    timezone: payload.timezone,
    status: payload.status as Business["status"],
    usersCount: payload.users_count,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/**
 * `GET /api/v1/platform/businesses` — paginated platform-wide business list.
 *
 * Supports search, status filter, sorting and pagination.
 */
export async function listBusinesses(
  filters: BusinessFilters = {},
  page = 1,
): Promise<PaginatedData<Business>> {
  const params = {
    ...filters,
    page,
  };

  const data = await apiList<BusinessPayload>("/platform/businesses", { params });

  return {
    items: data.items.map(toBusiness),
    pagination: data.pagination,
  };
}

/**
 * `POST /api/v1/platform/businesses` — create a new business (tenant).
 *
 * The request body matches `StoreBusinessRequest`.
 */
export async function createBusiness(values: BusinessFormValues): Promise<Business> {
  const payload = await apiPost<BusinessPayload>("/platform/businesses", toBusinessInput(values));
  return toBusiness(payload);
}

/**
 * `GET /api/v1/platform/businesses/{business}` — fetch one business by id.
 */
export async function getBusiness(businessId: number): Promise<Business> {
  const payload = await apiGet<BusinessPayload>(`/platform/businesses/${businessId}`);
  return toBusiness(payload);
}

/**
 * `PUT|PATCH /api/v1/platform/businesses/{business}` — update business master data.
 *
 * The request body matches `UpdateBusinessRequest` (all fields optional).
 */
export async function updateBusiness(businessId: number, values: BusinessFormValues): Promise<Business> {
  const payload = await apiPatch<BusinessPayload>(`/platform/businesses/${businessId}`, toBusinessInput(values));
  return toBusiness(payload);
}

/**
 * `PATCH /api/v1/platform/businesses/{business}/status` — activate or deactivate a business.
 */
export async function updateBusinessStatus(businessId: number, status: Business["status"]): Promise<Business> {
  const payload = await apiPatch<BusinessPayload>(`/platform/businesses/${businessId}/status`, { status });
  return toBusiness(payload);
}

/**
 * `DELETE /api/v1/platform/businesses/{business}` — soft-delete a business with no tenant data.
 */
export async function deleteBusiness(businessId: number): Promise<void> {
  await apiDelete<void>(`/platform/businesses/${businessId}`);
}

/**
 * `POST /api/v1/platform/businesses/{business}/admin` — provision the first Business Admin for a business.
 *
 * The request body matches `StoreBusinessAdminRequest`.
 */
export async function provisionFirstAdmin(businessId: number, values: BusinessAdminFormValues): Promise<BusinessAdminResponse> {
  const response = await apiPost<BusinessAdminResponse>(`/platform/businesses/${businessId}/admin`, toAdminInput(values));
  return response;
}

/** Maps business form values onto the API body (snake_case). */
function toBusinessInput(values: BusinessFormValues) {
  return {
    name: values.name.trim(),
    phone: values.phone.trim() || null,
    email: values.email.trim() || null,
    address: values.address.trim() || null,
    logo: values.logo.trim() || null,
    currency: values.currency.trim().toUpperCase() || null,
    timezone: values.timezone.trim() || null,
    status: values.status,
  };
}

/** Maps admin form values onto the API body (snake_case). */
function toAdminInput(values: BusinessAdminFormValues) {
  return {
    name: values.name.trim(),
    email: values.email.trim().toLowerCase(),
    phone: values.phone.trim() || null,
    branch: values.branch.trim() || null,
    notes: values.notes.trim() || null,
    password: values.password,
    password_confirmation: values.passwordConfirmation,
  };
}