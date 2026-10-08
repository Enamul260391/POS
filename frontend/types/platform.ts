/**
 * Platform Business types, mirroring `BusinessResource` from the backend.
 *
 * The Platform Owner manages businesses as tenants. The resource exposes only
 * tenant-identifying master data and status. `settings` is tenant internals
 * and never leaves the server; `deleted_at` is an internal concern.
 */
export type BusinessStatus = "active" | "inactive";

export interface Business {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  logo: string | null;
  currency: string | null;
  timezone: string | null;
  status: BusinessStatus;
  usersCount: number | null;
  createdAt: string;
  updatedAt: string;
}

export interface BusinessPayload {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  logo: string | null;
  currency: string | null;
  timezone: string | null;
  status: string;
  users_count: number | null;
  created_at: string;
  updated_at: string;
}

/** Valid status options for forms and filters. */
export const BUSINESS_STATUS_OPTIONS: readonly { value: BusinessStatus; label: string }[] = [
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

/** Field names a `422` may address, in the frontend's camelCase spelling. */
export const BUSINESS_FORM_FIELDS = [
  "name",
  "phone",
  "email",
  "address",
  "logo",
  "currency",
  "timezone",
  "status",
] as const;

/** Form values for creating/editing a business. */
export interface BusinessFormValues {
  name: string;
  phone: string;
  email: string;
  address: string;
  logo: string;
  currency: string;
  timezone: string;
  status: BusinessStatus;
}

/** Blank state for "Create business". */
export const EMPTY_BUSINESS_VALUES: BusinessFormValues = {
  name: "",
  phone: "",
  email: "",
  address: "",
  logo: "",
  currency: "USD",
  timezone: "UTC",
  status: "active",
};

/** Maps a `Business` from the API into form state (edit mode). */
export function toBusinessFormValues(business: Business): BusinessFormValues {
  return {
    name: business.name,
    phone: business.phone ?? "",
    email: business.email ?? "",
    address: business.address ?? "",
    logo: business.logo ?? "",
    currency: business.currency ?? "USD",
    timezone: business.timezone ?? "UTC",
    status: business.status,
  };
}

/** Maps form values onto the API body (snake_case). */
export function toBusinessInput(values: BusinessFormValues) {
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

/** Form values for creating the first Business Admin. */
export interface BusinessAdminFormValues {
  name: string;
  email: string;
  phone: string;
  branch: string;
  notes: string;
  password: string;
  passwordConfirmation: string;
}

/** Blank state for "Create first Business Admin". */
export const EMPTY_BUSINESS_ADMIN_VALUES: BusinessAdminFormValues = {
  name: "",
  email: "",
  phone: "",
  branch: "",
  notes: "",
  password: "",
  passwordConfirmation: "",
};

/** Response from the first Business Admin provisioning endpoint. */
export interface BusinessAdminResponse {
  id: number;
  business_id: number;
  is_platform_owner: boolean;
  name: string;
  email: string;
  created_at: string;
  roles: string[];
  capabilities: string[];
}