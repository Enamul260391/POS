import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { Product, ProductPayload } from "@/types/product";

import { toProduct, toProducts } from "../lib/productMapper";

/**
 * Product service — the only module that talks to `/products`.
 *
 * Mapping is applied here, so screens never see a `snake_case` payload and the
 * field-name differences between `ProductResource` and `@/types/product` stay
 * in one file.
 *
 * Tenancy: no request carries a `business_id`. Laravel resolves the owning
 * business from the bearer token, and `category_id` is validated against *that*
 * business, so a product can never be attached to another tenant's category.
 */

/** Largest page the backend allows (`ProductService::MAX_PER_PAGE`). */
const MAX_PER_PAGE = 100;

/** Query parameters accepted by `GET /products` (`IndexProductRequest`). */
export interface ProductFilters {
  /** Free text over name, SKU and barcode. */
  search?: string;
  categoryId?: number | null;
  status?: "active" | "inactive";
  sku?: string;
  barcode?: string;
  page?: number;
  perPage?: number;
  sort?: "name" | "sku" | "selling_price" | "cost_price" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /products` (`StoreProductRequest`). */
export interface ProductInput {
  name: string;
  categoryId?: number | null;
  sku?: string | null;
  barcode?: string | null;
  description?: string | null;
  costPrice?: number | null;
  sellingPrice?: number | null;
  taxRate?: number | null;
  unit?: string | null;
  lowStockThreshold?: number | null;
  image?: string | null;
  status?: "active" | "inactive";
  /**
   * Opening stock, accepted **only** at creation time: the API writes it to the
   * ledger as a stock movement instead of storing it on the product.
   */
  openingStock?: number | null;
}

/** Body of `PUT|PATCH /products/{id}` (`UpdateProductRequest`). */
export type ProductUpdateInput = Omit<ProductInput, "openingStock">;

/** Maps the frontend filter object onto the query string. */
function toQuery(filters: ProductFilters | undefined): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    category_id: filters.categoryId,
    status: filters.status,
    sku: filters.sku,
    barcode: filters.barcode,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/** Shared body builder, so create and update can never drift apart. */
function toProductBody(
  input: ProductInput | ProductUpdateInput,
): Record<string, unknown> {
  return {
    name: input.name,
    category_id: input.categoryId ?? null,
    sku: input.sku ?? null,
    barcode: input.barcode ?? null,
    description: input.description ?? null,
    cost_price: input.costPrice ?? null,
    selling_price: input.sellingPrice ?? null,
    tax_rate: input.taxRate ?? null,
    unit: input.unit ?? null,
    low_stock_threshold: input.lowStockThreshold ?? null,
    image: input.image ?? null,
    status: input.status ?? "active",
  };
}

/** `GET /products` — one page, filtered and sorted server-side. */
export async function listProducts(
  filters?: ProductFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Product>> {
  const data = await apiList<ProductPayload>("/products", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toProducts(data.items), pagination: data.pagination };
}

/**
 * `GET /products` without pagination, for the screens that manage the whole
 * catalogue at once (`/products`, `/inventory`, `/purchases`).
 *
 * The backend caps a page at 100, so a larger catalogue is walked page by page
 * here rather than unbounded. The POS deliberately does **not** use this: it
 * never loads the full catalogue, and searches server-side instead (see
 * {@link searchSellableProducts}).
 */
export async function listAllProducts(
  filters?: Omit<ProductFilters, "page" | "perPage">,
  config?: ApiRequestConfig,
): Promise<Product[]> {
  const perPage = 100;
  const first = await listProducts(
    { ...filters, page: 1, perPage },
    { signal: config?.signal },
  );

  if (first.pagination.last_page <= 1) {
    return first.items;
  }

  const rest = await Promise.all(
    Array.from({ length: first.pagination.last_page - 1 }, (_, index) =>
      listProducts(
        { ...filters, page: index + 2, perPage },
        { signal: config?.signal },
      ),
    ),
  );

  return [...first.items, ...rest.flatMap((page: PaginatedData<Product>) => page.items)];
}

/**
 * `GET /products?search=…&status=active` — the POS product search.
 *
 * The search is executed **by the backend** (a `LIKE` over name, SKU and
 * barcode), so the terminal only ever holds the rows that match what the
 * cashier typed. Nothing here walks the catalogue: the POS deliberately does
 * not download the full product list to filter it in the browser, which is why
 * this returns a single page rather than paging through every match — a broad
 * term must not be able to turn into a full-catalogue download.
 *
 * `category_id` is passed straight through, so selecting a category tab narrows
 * the search in SQL too instead of filtering the returned page locally.
 *
 * An empty term resolves to no request at all and no rows.
 */
export async function searchSellableProducts(
  search: string,
  filters?: { categoryId?: number | null },
  config?: ApiRequestConfig,
): Promise<Product[]> {
  const term = search.trim();
  if (term === "") {
    return [];
  }

  const page = await listProducts(
    {
      search: term,
      // Only active products may enter a sale, so the POS never even receives
      // an inactive row (the server enforces this filter itself).
      status: "active",
      categoryId: filters?.categoryId ?? null,
      page: 1,
      perPage: MAX_PER_PAGE,
    },
    { signal: config?.signal },
  );

  return page.items;
}

/** `GET /products/{id}` — one product with its ledger balance. */
export async function getProduct(
  id: number,
  config?: ApiRequestConfig,
): Promise<Product> {
  const payload = await apiGet<ProductPayload>(`/products/${id}`, {
    signal: config?.signal,
  });
  return toProduct(payload);
}

/**
 * `GET /products` lookup by exact barcode or SKU — the scanner path.
 *
 * Uses the dedicated `barcode` / `sku` query parameters so the match is an
 * indexed equality rather than a `LIKE` over the whole catalogue, and a scanner
 * hit costs one request per code regardless of catalogue size.
 */
export async function findProductByCode(
  code: string,
  filters?: Pick<ProductFilters, "status">,
  config?: ApiRequestConfig,
): Promise<Product | null> {
  const value = code.trim();
  if (value === "") {
    return null;
  }

  const byBarcode = await listProducts(
    { ...filters, barcode: value, perPage: 1, page: 1 },
    { signal: config?.signal },
  );
  const match = byBarcode.items[0];
  if (match !== undefined) {
    return match;
  }

  const bySku = await listProducts(
    { ...filters, sku: value, perPage: 1, page: 1 },
    { signal: config?.signal },
  );
  return bySku.items[0] ?? null;
}

/** `POST /products` — creates a product, seeding the ledger when opening stock is given. */
export async function createProduct(input: ProductInput): Promise<Product> {
  const payload = await apiPost<ProductPayload>("/products", {
    ...toProductBody(input),
    opening_stock: input.openingStock ?? null,
  });
  return toProduct(payload);
}

/** `PUT|PATCH /products/{id}` — partial update; never carries stock. */
export async function updateProduct(
  id: number,
  input: ProductUpdateInput,
): Promise<Product> {
  const payload = await apiPatch<ProductPayload>(
    `/products/${id}`,
    toProductBody(input),
  );
  return toProduct(payload);
}

/**
 * `DELETE /products/{id}` — soft-deletes a product.
 *
 * A product that already appears on a sale or purchase is refused by the
 * service layer (a `409`); the shared error handling surfaces that message
 * verbatim rather than inventing a wording.
 */
export async function deleteProduct(id: number): Promise<void> {
  await apiDelete<null>(`/products/${id}`);
}