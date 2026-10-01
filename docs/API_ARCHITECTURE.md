# POS API Architecture

Applies to: `backend/` — Laravel 12 REST API (`/api/v1`).
Frontend: Next.js App Router (separate application, integrated in a later phase).

> **Deploying?** See [`DEPLOYMENT.md`](DEPLOYMENT.md) for the production
> checklist, environment variables, backup/restore requirements, smoke test and
> rollback plan. This document covers the API contract only.

---

## 1. Base URL & Versioning

| Environment | Base URL |
| ----------- | -------- |
| Local | `http://localhost:8000/api/v1` |
| Production | `https://<host>/api/v1` |

* Every endpoint lives under `/api/v1` — the version is part of the URL prefix, so a
  breaking change adds a `v2` route group without touching `v1`.
* Controllers live in `App\Http\Controllers\Api\V{n}` (today: `Api\V1`).
* Requests and responses are JSON only. Send `Accept: application/json`.

Existing endpoints:

| Method | URI | Auth |
| ------ | --- | ---- |
| `GET` | `/api/v1/health` | public |
| `POST` | `/api/v1/auth/login` | public |
| `POST` | `/api/v1/auth/logout` | `auth:sanctum` |
| `GET` | `/api/v1/auth/me` | `auth:sanctum` |
| `GET` `POST` | `/api/v1/roles` | `roles.view` / `roles.create` |
| `GET` | `/api/v1/roles/{role}` | `roles.view` |
| `PUT` `PATCH` | `/api/v1/roles/{role}` | `roles.update` |
| `DELETE` | `/api/v1/roles/{role}` | `roles.delete` |
| `GET` | `/api/v1/permissions` | `permissions.view` |
| `PUT` `PATCH` | `/api/v1/users/{user}/roles` | `users.roles.update` |
| `GET` | `/api/v1/settings` | `settings.view` |
| `PUT` `PATCH` | `/api/v1/settings` | `settings.update` |

---

## 2. Response Envelope

The envelope is produced by exactly one class: `App\Support\ApiResponse`.
Controllers reach it through the `App\Http\Traits\ApiResponse` trait
(`$this->success()`, `$this->error()`, `$this->validation()`, `$this->paginated()`).
Never hand-build the envelope.

### Success

```json
{
  "success": true,
  "message": "Product retrieved successfully",
  "data": { "id": 1, "name": "Product" }
}
```

### Error

```json
{
  "success": false,
  "message": "Something went wrong",
  "errors": {}
}
```

`errors` is always present: an empty object for non-field errors, a field → messages
map for validation failures.

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": { "email": ["The email field must be a valid email address."] }
}
```

### Paginated list

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "items": [],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 100,
      "last_page": 5,
      "from": 1,
      "to": 20
    }
  }
}
```

Build it with `$this->paginated($paginator, 'Products retrieved successfully', ProductResource::class)`
(`App\Support\ApiResponse::paginated()`). There is deliberately **no** `data.data` /
`links` / `meta` nesting: list payloads always look the same.

## 3. HTTP Status Codes

| Status | Used for |
| ------ | -------- |
| `200 OK` | successful `GET`, update, action, logout |
| `201 Created` | successful resource creation |
| `204 No Content` | only when no body is intentionally returned (not used for standard replies) |
| `400 Bad Request` | malformed / invalid request |
| `401 Unauthorized` | missing or invalid authentication |
| `403 Forbidden` | authenticated but not allowed |
| `404 Not Found` | resource does not exist |
| `409 Conflict` | business / resource conflict |
| `422 Unprocessable Entity` | validation failure |
| `429 Too Many Requests` | rate limiting |
| `500 Internal Server Error` | unexpected server error |

Status codes are mapped centrally — see `App\Exceptions\ApiExceptionRenderer`.

---

## 4. Exception Handling

Configured in `bootstrap/app.php`, implemented by `App\Exceptions\ApiExceptionRenderer`:

| Exception | Status | Message |
| --------- | ------ | ------- |
| `ValidationException` | 422 | `Validation failed` + field errors |
| `AuthenticationException` | 401 | `Unauthenticated` |
| `AuthorizationException` / `AccessDeniedHttpException` | 403 | `Forbidden` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `Resource not found` |
| other `HttpException` | its own status | canonical message (e.g. `Too many requests` for 429) |
| any other `Throwable` | 500 | `Something went wrong` |

Two guarantees:

1. **API traffic always gets JSON.** Everything under `/api/*` is rendered as the
   envelope, including requests that do not send `Accept: application/json`.
2. **Web traffic is untouched.** Non-API requests keep Laravel's normal HTML error
   pages (and its debug page in local development).

Never returned to clients: exception messages, stack traces, SQL, credentials, file
paths, environment values. The full exception is still written to the normal Laravel
log (`storage/logs/laravel.log`) — debug from the log, not the response. The 500 body
is generic in **every** environment, which keeps the API contract stable.

Authentication failures are never redirected to a login page: `auth:sanctum` failures
produce `401 { "success": false, "message": "Unauthenticated", "errors": {} }`.

Business wording belongs to the service/controller layer (which owns the domain
language and returns it with `ApiResponse::error($message, [], 409)`) — the exception
mapper only guarantees a safe default per status code.

---

## 5. Authentication

* Laravel Sanctum personal access tokens, sent as `Authorization: Bearer {token}`.
* `POST /api/v1/auth/login` returns the token:

```json
{
  "success": true,
  "message": "Login successful",
  "data": { "token": "...", "token_type": "Bearer", "user": { "...": "..." } }
}
```

* `POST /api/v1/auth/logout` revokes only the token used for the request.
* Token auth is intentional; SPA cookie auth (`EnsureFrontendRequestsAreStateful`) is
  not enabled.

## 6. Layer Responsibilities

```
Route  ->  Controller  ->  Form Request  ->  Service  ->  Model / DB
                              |                              |
                              v                              v
                       ApiResponse  <-  API Resource  <-  Eloquent model
```

| Layer | Location | Responsibility |
| ----- | -------- | -------------- |
| Route | `routes/api.php` | URL, name, middleware, version prefix. One prefix + name group per domain. |
| Controller | `app/Http/Controllers/Api/V1` | Thin adapter: resolve input, call the service, return a resource/response. No business logic, no large queries, no transactions. |
| Form Request | `app/Http/Requests/<Domain>` | All input validation (`LoginRequest` today). |
| Service | `app/Services/<Domain>` | Business rules, transactions, multi-model workflows (`AuthService` today). |
| Model | `app/Models` | Relationships, casts, scopes. |
| Resource | `app/Http/Resources` | Explicit output shape. A model's `toArray()` is never returned raw for a public response, so sensitive columns (`password`, `remember_token`, token hashes) cannot leak. |

Future domains get one folder per layer, e.g. `Services/Product/ProductService.php`
with `Requests/Product/StoreProductRequest.php` and `Resources/ProductResource.php`.
Directories are created when the domain is implemented — not in advance.

---

## 7. Rate Limiting

Two independent mechanisms, deliberately different in kind. Both are configured
in `config/rate-limiting.php` and tunable from `.env`. Redis is **not** required —
both use the default cache store (`file` or `database`).

### 7.1 API flood guard — `throttle:api`

* `bootstrap/app.php` applies `throttle:api` to the whole `api` middleware group.
* The `api` limiter is defined in `AppServiceProvider::configureRateLimiting()`:
  **120 requests / minute**, keyed by authenticated user id, falling back to IP
  address for public endpoints.
* Counts **every** request, successful or not. It exists to stop a runaway
  client, not a human, so it is generous by design.
* Tuning: `API_RATE_LIMIT_PER_MINUTE`, `API_RATE_LIMIT_DECAY_MINUTES`.

### 7.2 Failed sign-in lockout — `App\Support\LoginThrottle`

Applied inside `AuthController::login()` (plus two hooks on `LoginRequest` for
the pre-validation path), **not** as a `throttle:` middleware.

The reason is that Laravel's `ThrottleRequests` middleware charges *every*
request it sees, so a middleware-based login limiter bills a **successful**
sign-in against the same budget as a wrong password, and can never refund it.
A cashier signing out and back in, or reloading the app, was therefore locked
out with a *correct* password. Only the controller knows the outcome, so the
accounting lives there.

| Property | Behaviour |
| -------- | --------- |
| Counts | Failed sign-ins only. A success is never charged. |
| Refund | A successful sign-in **clears** the counter, so a mistyped password never outlives the success that followed it. |
| Buckets | Two, evaluated independently (stricter wins):<br>• **account** — e-mail + IP → 5 / 60 s<br>• **ip** — IP alone → 20 / 60 s |
| E-mail rotation | Defeated by the per-IP bucket. A per-account key alone is trivially bypassed by varying the address. |
| A `422` | Charged like a wrong password, so the endpoint cannot be probed for free with malformed payloads. |
| Locked out | Refused **before** the password is hashed, so a locked-out caller cannot burn server CPU with bcrypt. |
| Response | `429 { "success": false, "message": "Too many failed sign-in attempts. Try again in N seconds.", "errors": {} }` plus a `Retry-After` header. |

Tuning: `LOGIN_RATE_LIMIT_MAX_ATTEMPTS`, `LOGIN_RATE_LIMIT_DECAY_SECONDS`,
`LOGIN_IP_RATE_LIMIT_MAX_ATTEMPTS`, `LOGIN_IP_RATE_LIMIT_DECAY_SECONDS`.
Do not raise the per-account limit above 10 or drop its decay below 30 s.

`Retry-After` and the `X-RateLimit-*` headers are listed in
`config/cors.php → exposed_headers`; without that a cross-origin browser cannot
read them.

### 7.3 Failure envelope

* Exceeding either limiter returns the standard `429` envelope.
* Covered by `tests/Feature/Auth/LoginThrottleTest.php` — the lockout is
  failure-only, is refunded by a success, and cannot be escaped by rotating
  e-mail addresses.

---

## 8. Authorization (Roles, Permissions, Settings)

Phase 24B introduced permission-based authorization. It reuses the existing
layers — Sanctum for identity, the standard error envelope, Form Requests and
Services — and adds exactly one new concept: **capabilities**.

### Where the permission logic lives

| Piece | Location | Responsibility |
| ----- | -------- | -------------- |
| Capability names + slug resolution | `App\Services\Permission\PermissionService` | The single source of truth for "what may a user do?". |
| Gate registration | `AppServiceProvider::configurePermissionGates()` | Registers one `Gate::define()` per capability. |
| User helpers | `User::hasPermission()`, `hasAnyPermission()`, `hasRole()` | Readable checks for controllers, services and future phases. |
| Route enforcement | `can:<capability>` middleware | Standard Laravel middleware; no custom middleware was needed. |

`$user->hasPermission('roles.update')`, `$user->can('roles.update')` and the
route middleware all resolve through the same Gate, so they cannot disagree.
Nothing re-implements permission logic in a controller.

### Permission naming

The `permissions` table is a **global** catalogue (no `business_id`): a
permission is a system-wide definition and each business grants a subset to its
own roles. Permissions are therefore **read-only over the API** — there is no
create/update/delete, because exposing CRUD would let any caller mutate a global
catalogue that is not a tenant-level concern.

The API authorises against fine-grained capability names, while the pre-existing
seeded catalogue uses the coarser `<resource>.manage` convention. Rather than
inventing a second naming system or forcing a reseed, **each capability accepts
either** slug:

| Capability | Accepted permission slugs |
| ---------- | ------------------------- |
| `roles.view` | `roles.view`, `roles.manage` |
| `roles.create` | `roles.create`, `roles.manage` |
| `roles.update` | `roles.update`, `roles.manage` |
| `roles.delete` | `roles.delete`, `roles.manage` |
| `permissions.view` | `permissions.view`, `roles.manage` |
| `users.roles.update` | `users.roles.update`, `users.manage`, `roles.manage` |
| `settings.view` | `settings.view`, `settings.manage` |
| `settings.update` | `settings.update`, `settings.manage` |
| `categories.view` / `.create` / `.update` / `.delete` | same slug, plus `categories.manage` |
| `products.view` / `.create` / `.update` / `.delete` | same slug, plus `products.manage` |
| `inventory.view` | `inventory.view`, `inventory.manage` |
| `inventory.manage` | `inventory.manage` (stock adjustment) |
| `customers.view` / `.create` / `.update` / `.delete` | same slug, plus `customers.manage` |
| `suppliers.view` / `.create` / `.update` / `.delete` | same slug, plus `suppliers.manage` |
| `purchases.view` / `.create` / `.update` / `.delete` | same slug, plus `purchases.manage` |
| `sales.view` / `.create` / `.update` / `.delete` | same slug, plus `sales.manage` |
| `sales.payments.create` | `sales.payments.create`, `sales.manage` |
| `sale_returns.view` / `.create` | same slug, plus `sale_returns.manage` |
| `expenses.view` / `.create` / `.update` / `.delete` | same slug, plus `expenses.manage` |
| `expense_categories.view` / `.create` / `.update` / `.delete` | same slug, plus `expense_categories.manage`, `expenses.manage` |
| `cash_registers.view` / `.create` / `.update` | same slug, plus `cash_registers.manage` |
| `cash_registers.manage` | `cash_registers.manage` (cash in / cash out) |
| `reports.view` | `reports.view` (reports are read-only) |

**The effective rule:** a pre-existing `<resource>.manage` row authorises the
whole domain, exactly as it did before enforcement. A role seeded with only
`products.manage` keeps full product access; a role granted the newer
`products.view` can additionally be restricted to reading. The fine-grained rows
are an *option*, never a requirement, so no existing installation loses
functionality.

Two capabilities keep their coarse slug as the canonical name because that row
already exists and means exactly the right thing: `inventory.manage` (a stock
adjustment bypasses the purchase/sale flow) and `cash_registers.manage` (a manual
cash movement). Naming them `inventory.adjust` / `cash_registers.cash_in` would
add rows that mean the same as one already installed.

`sales.payments.create` is separate from `sales.manage` on purpose: taking money
is not the same authority as being able to raise or amend a sale, so a cashier
who may collect payment need not be able to create sales.

`RolesAndPermissionsSeeder` seeds both sets and is idempotent (re-runnable
without violating the unique indexes on `permissions.slug`, `roles.slug` and
`permission_role`). Grants are additive: re-seeding adds missing pairs and never
revokes one.

### Seeded role matrix

`RolesAndPermissionsSeeder` creates `admin`, `manager`, `salesman` and
`accountant` for every business. **There is no `cashier` role** — the till-facing
seeded role is `salesman`. The grants below are derived from each role's own
description in the seeder, using the coarse rows:

| Role | Grants | Description |
| ---- | ------ | ----------- |
| `admin` | the entire catalogue | "Full access to all features" |
| `manager` | products, categories, inventory, sales, sale returns, purchases, customers, suppliers, expenses, cash registers, `reports.view` | "Manage products, sales, purchases, and reports" |
| `salesman` | products, categories, sales, customers, cash registers | "Handle sales and customer management" |
| `accountant` | purchases, suppliers, expenses, expense categories, `sales.payments.create`, `reports.view` | "Manage purchases, expenses, and payments" |

The accountant deliberately does **not** receive `sales.manage`: it may take
payment against a sale without being able to create one.

Before P1-2 these roles were created but granted nothing. That was harmless
while the operational endpoints accepted any authenticated user; granting them
their documented capabilities is what keeps enforcement from locking out every
non-admin user.

### Operational endpoints

Every operational route is `auth:sanctum` + `active` + `can:<capability>`, in
that order. `GET`/list/detail maps to `*.view`, `POST` to `*.create`, `PUT`/
`PATCH` to `*.update` and `DELETE` to `*.delete`.

| Domain | Method | Endpoint | Capability |
| ------ | ------ | -------- | ---------- |
| Categories | `GET` / `POST` | `/categories` | `categories.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/categories/{id}` | `categories.view` / `.update` / `.delete` |
| Products | `GET` / `POST` | `/products` | `products.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/products/{id}` | `products.view` / `.update` / `.delete` |
| Inventory | `GET` | `/stock-movements` | `inventory.view` |
| | `POST` | `/products/{id}/stock-adjustments` | `inventory.manage` |
| Customers | `GET` / `POST` | `/customers` | `customers.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/customers/{id}` | `customers.view` / `.update` / `.delete` |
| Suppliers | `GET` / `POST` | `/suppliers` | `suppliers.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/suppliers/{id}` | `suppliers.view` / `.update` / `.delete` |
| Purchases | `GET` / `POST` | `/purchases` | `purchases.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/purchases/{id}` | `purchases.view` / `.update` / `.delete` |
| Sales | `GET` / `POST` | `/sales` | `sales.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/sales/{id}` | `sales.view` / `.update` / `.delete` |
| | `POST` | `/sales/{id}/payments` | `sales.payments.create` |
| Sale returns | `GET` / `POST` | `/sale-returns` | `sale_returns.view` / `.create` |
| | `GET` | `/sale-returns/{id}` | `sale_returns.view` |
| Expenses | `GET` / `POST` | `/expenses` | `expenses.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/expenses/{id}` | `expenses.view` / `.update` / `.delete` |
| Expense categories | `GET` / `POST` | `/expense-categories` | `expense_categories.view` / `.create` |
| | `GET` / `PUT` / `DELETE` | `/expense-categories/{id}` | `expense_categories.view` / `.update` / `.delete` |
| Cash register | `GET` | `/cash-registers/current`, `/{id}`, `/{id}/summary`, `/{id}/transactions` | `cash_registers.view` |
| | `POST` | `/cash-registers/open` | `cash_registers.create` |
| | `POST` | `/cash-registers/{id}/close` | `cash_registers.update` |
| | `POST` | `/cash-registers/{id}/transactions` | `cash_registers.manage` |
| Reports | `GET` | `/reports/dashboard`, `/sales`, `/purchases`, `/expenses`, `/profit-loss`, `/inventory` | `reports.view` |

The resources are declared as explicit routes rather than `Route::apiResource()`
because each verb needs its *own* capability: `apiResource()` applies a single
middleware stack to all five verbs, which would either over-grant writes to
readers or under-grant them to editors.

The only routes left on `auth:sanctum` + `active` alone are `/health`
(public), `/auth/login` (public), `/auth/logout` and `/auth/me` (identity
infrastructure — there is no business capability to check).

### 401 vs 403 vs 404

| Situation | Status |
| --------- | ------ |
| No / invalid token | `401 Unauthenticated` |
| Valid token, missing permission | `403 Forbidden` |
| Valid token, resource belongs to another business | `404 Resource not found` |

`auth:sanctum` runs before `can:`, so an anonymous caller never receives a 403
that would confirm the endpoint exists.

A cross-business resource returns **404, not 403**: reporting 403 would confirm
that the record exists. Business A therefore cannot detect, read, update or
delete Business B's roles, users or settings.

### Business isolation

Tenancy is enforced in the **service layer**, not by route model binding. Route
parameters are raw ids and every lookup goes through a business-scoped query
(`Role::forBusiness()`, `User::where('business_id', ...)`, etc.), so implicit
binding can never hand a foreign model to a controller. `business_id` is never
read from a request body; it always comes from the authenticated user.

### Endpoints

| Method | Endpoint | Permission | Notes |
| ------ | -------- | ---------- | ----- |
| `GET` | `/roles` | `roles.view` | Paginated, searchable (`?page=`, `?per_page=`, `?search=`). |
| `POST` | `/roles` | `roles.create` | `201`; accepts `name`, `description`, `permission_ids`. |
| `GET` | `/roles/{role}` | `roles.view` | |
| `PUT` / `PATCH` | `/roles/{role}` | `roles.update` | Partial: omit `permission_ids` to keep grants. |
| `DELETE` | `/roles/{role}` | `roles.delete` | `409` if still assigned to a user. |
| `GET` | `/permissions` | `permissions.view` | Paginated, searchable. Global — no tenant scoping. |
| `PUT` / `PATCH` | `/users/{user}/roles` | `users.roles.update` | Replaces the full set (idempotent). |
| `GET` | `/settings` | `settings.view` | Only the caller's business. |
| `PUT` / `PATCH` | `/settings` | `settings.update` | Upserts the supplied keys. |

**Roles.** Request body:

```json
{ "name": "Manager", "description": "Store manager", "permission_ids": [1, 2, 3] }
```

`business_id` is never accepted. The slug is derived from `name` and is unique
per business; the role row and its `permission_role` rows are written in one
transaction, so an invalid permission id rolls the whole request back (`422`).
Deleting a role that is still assigned returns `409` rather than silently
stripping authorisation from live accounts; `role_user` / `permission_role`
cascade on delete, so no orphan rows survive.

Response (`data` is a `RoleResource`):

```json
{
  "id": 1, "name": "Manager", "slug": "manager", "description": "Store manager",
  "permissions": [{ "id": 2, "name": "Roles Update", "slug": "roles.update" }],
  "users_count": 2,
  "created_at": "...", "updated_at": "..."
}
```

**Permissions.** Response item:
`{ "id": 1, "name": "Roles Update", "slug": "roles.update", "description": "..." }`.

**User roles.** Request body: `{ "role_ids": [1, 2] }`. `role_ids` must be
present (an explicit `[]` detaches every role) and may only contain roles of the
**caller's** business — a foreign role is rejected with `422`, and a mixed batch
is rejected in full (the valid half is not applied). A target user from another
business is `404`.

**Settings.** `business_settings` is a key/value store, so the update body is a
flat map of the keys to write (unmentioned keys are untouched):

```json
{ "receipt_footer": "Thank you", "invoice_prefix": "INV" }
```

There is no `{business}` segment to tamper with. Two protections apply
(`SettingsService`):

* **Reserved keys** (`business_id`, `id`, `key`, `value`, timestamps) are
  refused, so a setting key can never shadow an internal column.
* **Sensitive keys** (anything containing `secret`, `password`, `token`,
  `api_key`, `private_key`, `credential`, `signature`, …) are write-protected
  **and filtered out of responses**, so a credential that exists in the table
  can never be returned by the API. They can only be provisioned out of band.

The response contains only `key`/`value` pairs — no `business_id`, no
timestamps:

```json
{ "success": true, "message": "Settings retrieved successfully",
  "data": [{ "key": "receipt_footer", "value": "Thank you" }] }
```

---

## 9. Tests

* `tests/Unit/ApiResponseTest.php` — envelope + pagination contract.
* `tests/Unit/ModelStructureTest.php` — model shape and relationship contracts.
* `tests/Feature/Api/ApiArchitectureTest.php` — health, 404/405/401/422/429/500
  envelopes, sanitised errors, JSON for API paths, HTML preserved for web paths.
* `tests/Feature/Auth/AuthTest.php` — authentication behaviour (phase 18).
* `tests/Feature/Role/RoleApiTest.php` — role CRUD, permission sync, isolation.
* `tests/Feature/Role/RoleOwnershipTest.php` — `roles.business_id` (phase 24A).
* `tests/Feature/Permission/PermissionApiTest.php` — global, read-only catalogue.
* `tests/Feature/UserRole/UserRoleApiTest.php` — role sync + cross-tenant refusal.
* `tests/Feature/Settings/SettingsApiTest.php` — isolation, secrets, reserved keys.
* `tests/Feature/Authorization/RolePermissionAuthorizationTest.php` — 401/403/404
  per endpoint category and permission-resolution rules.

Run with `php artisan test`. Route inventory: `php artisan route:list --path=api`.

> Test note: `Auth::forgetGuards()` is called by the
> `InteractsWithPermissions` fixture whenever the acting identity changes.
> Laravel caches the resolved user for the lifetime of a test, so without it a
> second `withToken()` would silently keep the *previous* identity active and
> the per-permission assertions would be testing the wrong user.



