# DEPLOYMENT — Production Checklist

Scope: `backend/` (Laravel 12 REST API) and `frontend/` (Next.js App Router).
The two are deployed as **separate applications**. Nothing in this document
invents a hostname, IP, or version — every placeholder is marked `<...>` and
must be supplied by whoever owns the infrastructure.

**This document contains no real domain names or credentials.** Substitute your
own values at deploy time.

---

## 0. What this project does *not* need

Verified by inspecting the source. Do not provision these:

| Thing | Status | Evidence |
| --- | --- | --- |
| Redis | **Not required** | No cache, queue, session or broadcast driver is pointed at Redis. Rate limiting and cache work on the `database`/`file` drivers. |
| Queue worker | **Not required** | No job classes, no `ShouldQueue`, no `dispatch()` calls anywhere in `app/`. |
| Scheduler (cron) | **Not required** | No `Schedule::` definitions; `routes/console.php` only defines the stock `inspire` command. |
| `php artisan storage:link` | **Not required** | No `Storage::` usage and no user-uploaded files in the current application. Do **not** create the link. |
| Docker / Kubernetes / CI-CD / Sentry / CDN | **Not used** | No such configuration exists in the project. |
| Email transport | **Not required** | `MAIL_MAILER=log`; the application sends no email. |

If you later add a queued job, an email, or file uploads, revisit this table.

---

## 1. Server requirements

Taken from `backend/composer.json` and `frontend/package.json`. No versions are
invented — these are the versions the project actually declares and is verified
against.

**Backend (PHP)**

| Requirement | Value | Source |
| --- | --- | --- |
| PHP | `^8.2` (verified locally on 8.2.33) | `composer.json` → `require.php` |
| PHP extension | `ext-bcmath` (**required**, not optional) | `composer.json` → `require` |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo` | Laravel 12 requirements |
| Database | MySQL (see §4) | `config/database.php` |
| Composer | 2.x (verified on 2.10.3) | — |
| Web server | nginx or Apache with PHP-FPM | — |

> `ext-bcmath` is a hard requirement. `composer install --no-dev` will fail
> loudly without it, which is the intended behaviour.

**Frontend (Node)**

| Requirement | Value | Source |
| --- | --- | --- |
| Next.js | `16.3.5` | `package.json` |
| React | `19.2.8` | `package.json` |
| Node.js | Must support Next.js 16 (Node 20.9+). Verified locally on **20.20.2**. | — |
| npm | 10.x (verified on 10.8.2). `npm ci` is available — `package-lock.json` is committed. | — |

---

## 2. Environment variables

### 2.1 Backend — `backend/.env`

Create it from the template. **Never commit it.**

```bash
cp .env.example .env
php artisan key:generate          # writes APP_KEY — treat as a secret
```

| Variable | Production value | Notes |
| --- | --- | --- |
| `APP_ENV` | `production` | Required. |
| `APP_DEBUG` | `false` | **Required.** `true` leaks stack traces, SQL, file paths and env values. |
| `APP_URL` | `https://<api-host>` | Required. Must be the real HTTPS API origin. |
| `APP_KEY` | generated | Secret. Rotating it invalidates encrypted data and sessions. |
| `DB_CONNECTION` | `mysql` | The application targets MySQL everywhere. |
| `DB_HOST` / `DB_PORT` | `<db-host>` / `3306` | |
| `DB_DATABASE` | `<db-name>` | |
| `DB_USERNAME` / `DB_PASSWORD` | dedicated user / secret | **Do not use `root`.** |
| `CACHE_STORE` | `database` (or `file`) | Both fine. The `cache` table ships with the default migrations. |
| `QUEUE_CONNECTION` | `database` (or `sync`) | No worker runs either way — no jobs are dispatched. |
| `LOG_CHANNEL` | `stack` | |
| `LOG_STACK` | `daily` | Rotates logs and bounds disk usage. |
| `LOG_LEVEL` | `info` | `debug` is unnecessary overhead in production. |
| `LOG_DAILY_DAYS` | `14` (suggested) | Only read by the `daily` channel. |
| `BCRYPT_ROUNDS` | `12` | Do not lower. |
| `CORS_ALLOWED_ORIGINS` | `https://<frontend-host>` | **Required.** See §5. `*` is rejected at boot. |
| `TRUSTED_PROXIES` | `<proxy-ip>` if behind a proxy | See §5. Leave unset when PHP is reached directly. |
| `SESSION_SECURE_COOKIE` | leave unset | `config/session.php` auto-marks cookies secure when `APP_ENV=production`. |

### 2.2 Frontend — `frontend/.env.production` (build-time)

`NEXT_PUBLIC_*` values are **inlined into the browser bundle at build time** and
are visible to every user. Never put a secret in one.

| Variable | Production value |
| --- | --- |
| `NEXT_PUBLIC_API_BASE_URL` | `https://<api-host>/api/v1` |
| `NEXT_PUBLIC_APP_NAME` | `POS` (or your product name) |
| `NEXT_PUBLIC_API_TIMEOUT` | `30000` |

No production URL is hardcoded in the source. Changing this variable requires a
rebuild — it is not a runtime setting.

**Do not put the production API URL in `frontend/.env.local`.** That file is loaded
during `next build` *and* outranks `.env.production`, so it silently wins and ships
a bundle pointing at the developer's localhost. Use `.env.development.local` for
local values — see §5 for the full precedence table.

---

## 3. Secrets hygiene

* `backend/.env` — ignored by `backend/.gitignore` (`.env`, `.env.backup`,
  `.env.production`). Confirm with `git check-ignore .env` before the first commit.

## 4. Database

### 4.1 Create the database and user

Use a dedicated, least-privilege account. The application needs DDL rights only
while running migrations; day-to-day it needs DML.

```sql
CREATE DATABASE <db-name> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '<db-user>'@'<db-host>' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON <db-name>.* TO '<db-user>'@'<db-host>';
FLUSH PRIVILEGES;
```

### 4.2 Apply migrations

```bash
php artisan migrate --force
```

**`--force` is required in production** — without it Laravel refuses to run
migrations outside a local environment.

> **Never run against production:** `migrate:fresh`, `migrate:refresh`,
> `db:wipe`, `migrate:reset`. These drop every table and destroy all data.

Audit findings on the migration set (28 migrations):

* All money columns are `DECIMAL(15,2)`. **No `float`/`double` anywhere** — money
  is never stored in a binary floating-point column.
* Foreign keys are declared via `foreignId()->constrained()` with explicit
  `cascadeOnDelete` / `nullOnDelete` / `restrictOnDelete` behaviour.
* Migrations are additive (`create_*`, `add_*_to_*`). Only two use `Schema::table`,
  and both have a working `down()`.
* `2026_09_25_000000_add_business_id_to_roles_table` **deliberately aborts** if
  any role rows already exist, because ownership cannot be backfilled safely. On
  a fresh production database the `roles` table is empty at that point, so it
  succeeds. If it trips, resolve the data — do not edit the migration.
* Nothing in the migration set is dev-only; the application has no SQLite path.

### 4.3 Seeders

```bash
php artisan db:seed --force
```

`DatabaseSeeder` calls only `RolesAndPermissionsSeeder`, which is required for
the permission/role system to function.

**Never run `DevUserSeeder` in production.** It is deliberately excluded from
`DatabaseSeeder`, reads its credentials from the environment, and refuses to run
when `APP_ENV=production`.

---

## 5. CORS, HTTPS, cookies, Sanctum

### HTTPS is mandatory in production

* `APP_URL` must be `https://`.
* Terminate TLS at the reverse proxy and forward to PHP over the loopback
  interface.
* `NEXT_PUBLIC_API_BASE_URL` must be `https://`. The frontend does **not** force
  HTTP anywhere; it uses whatever the build-time variable says.

### CORS

The API and the frontend are **different origins**, so every browser request is
cross-origin and must pass a preflight. Both ends must be configured or login
fails in the browser while the API itself is healthy.

| Where | Variable | Must be |
| --- | --- | --- |
| Backend | `CORS_ALLOWED_ORIGINS` | the **frontend** origin, `https://<frontend-host>` |
| Frontend | `NEXT_PUBLIC_API_BASE_URL` | the **API** origin, `https://<api-host>/api/v1` |

They are different hosts. Setting `CORS_ALLOWED_ORIGINS` to the API's own origin
is the single most common cause of a login screen that "does nothing".

* Multiple origins: comma-separated (whitespace is trimmed automatically).
* **`*` is rejected at boot with a clear error.** `config/cors.php` enables
  `supports_credentials`, and the CORS specification forbids a wildcard origin
  combined with credentials — so a wildcard would fail in the browser with no
  server-side clue. Failing loudly at boot is intentional.
* **A localhost-only list is also rejected at boot when `APP_ENV=production`.**
  This was a real outage: the API kept the `http://localhost:3000` default, every
  preflight was answered with `Access-Control-Allow-Origin: http://localhost:3000`,
  and `https://<frontend-host>` never matched. The browser reported a CORS error
  for `POST /api/v1/auth/login`, and Laravel logged nothing at all.
* Local development (`http://localhost:3000`) keeps working unchanged — the guard
  only applies to `APP_ENV=production`.

### The `.env.local` trap (read this before building)

Next.js resolves env files in this order, **first match wins**:

| # | Source | Loaded during `next build`? |
| --- | --- | --- |
| 1 | real environment variables (CI/CD) | yes |
| 2 | `.env.$NODE_ENV.local` | yes |
| 3 | **`.env.local`** | **yes — and it outranks `.env.production`** |
| 4 | `.env.$NODE_ENV` (`.env.production`) | yes |
| 5 | `.env` | yes |

Because `.env.local` is loaded in production **and** takes priority over
`.env.production`, a developer's local file silently overrides the production API
URL. The build succeeds, the bundle ships pointing at `http://localhost:8000`, and
the browser fails every request with an error that points at CORS rather than at
the real cause.

This project therefore uses:

| File | Purpose |
| --- | --- |
| `.env.development.local` | local development values (`next dev` only) |
| `.env.production` | production values (`next build`) |
| `.env` | shared, environment-independent values |

`next.config.ts` enforces this: a **production** build whose
`NEXT_PUBLIC_API_BASE_URL` is unset, not `https://`, or points at localhost
**fails the build** with a message naming the variable. Set
`NEXT_PUBLIC_ALLOW_LOCAL_API=1` to deliberately build against a local API.

Verify after building — the wrong URL is otherwise invisible:

```bash
grep -r "localhost:8000" .next/static/chunks/   # must print nothing
```

### Reverse proxy / `TRUSTED_PROXIES`

Rate limiting keys on `$request->ip()` (`AppServiceProvider`). Behind a reverse
proxy without this setting, every user shares one throttle bucket and a single
mistyped password locks out the whole store.

```
TRUSTED_PROXIES=127.0.0.1              # single proxy
TRUSTED_PROXIES=10.0.0.1,10.0.0.2      # two proxies
```

Leave it **unset** when PHP-FPM is reached directly. Never use `*` unless the app
is reachable *only* through a proxy you control — a spoofed `X-Forwarded-For`
would then be trusted for rate limiting. This is opt-in precisely so the unsafe

---

## 6. Storage & permissions

The web server user (usually the PHP-FPM user) must be able to write to:

| Path | Why |
| --- | --- |
| `storage/` | logs, framework cache, compiled views |
| `bootstrap/cache/` | cached config/routes/packages/services |

```bash
chown -R <www-user>:<www-group> storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

**Do not use `chmod 777`.** Grant the web-server user ownership, and nothing
more. Both directories already ship the standard Laravel skeleton with the
correct ownership.

`php artisan storage:link` is **not** required — see §0. Do not create it.

---

## 7. Laravel production optimization

These three commands are **compatible with this application** and were each
executed successfully during verification. `config:cache` bakes the resolved
config (including CORS origins, `TRUSTED_PROXIES` and the session `secure` flag)
into a file, so **it must be rebuilt whenever `.env` changes**.

Recommended order for every release:

```bash
composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

* **After changing `.env` in production, re-run `php artisan config:cache`.**
  A stale cache silently keeps the old values.
* Reverting is safe: `php artisan optimize:clear` removes all cached files. This
  was verified during this phase.
* `route:cache` is safe here because the application uses no closure-based routes
  and no route that depends on a runtime-bound value.

> Never run `config:cache` in local development — a stale cached config
> confuses debugging. Use `php artisan optimize:clear` there instead.

---

## 8. Frontend production build

```bash
cd frontend
npm ci                 # package-lock.json is committed; use npm ci for reproducibility
npm run build
npm run start          # serves the production build
```

---

## 9. Database backup & restore

> **This is an operational requirement, not something the application
> implements.** No backup system exists in this codebase, and none was added. It
> must be provided by the infrastructure layer.

**Before every deployment**, take a verified backup:

```bash
mysqldump --single-transaction --routines --triggers \
          -u <db-user> -p <db-name> > backup-<timestamp>.sql
```

`--single-transaction` gives a consistent snapshot for InnoDB without locking
tables out of service.

**Ongoing requirements:**

* Automated daily backups (or more often, matching your recovery tolerance).
* Retention policy — keep daily/weekly/monthly tiers, e.g. 7 / 4 / 6.
* **Off-site or off-server copies.** A backup on the same disk as the database
  is not a backup.
* Encrypt backups at rest; they contain customer and payment data.
* Restrict access — backups are as sensitive as the live database.
* **Restore-test on a schedule** (at least quarterly). An untested backup is an
  assumption, not a safety net. Document the measured restore time; it is your
  real RTO.

---

## 10. Logging & monitoring

**Application logging (already in place):**

* `LOG_CHANNEL=stack` with `LOG_LEVEL=info` and `LOG_STACK=daily`.
* Errors are logged server-side in full by Laravel's handler, while API clients
  receive only a canonical message. `ApiExceptionRenderer` maps exceptions to safe
  responses; stack traces, SQL, file paths and env values never reach the client,
  and the full exception is still reported to the logger.
* The application does not swallow critical errors.

**Sensitive data in logs — current state:** the application performs **no
explicit `Log::` calls**, so it does not log request bodies, credentials or
tokens. The frontend HTTP client does not log tokens to the browser console.
Review this if logging is ever added.

**Operational monitoring to provide (infrastructure responsibility):**

| Signal | Why |
| --- | --- |
| API availability | Poll `GET /api/v1/health` (see §11). |
| 5xx rate | Rising 500s indicate real failures. |
| 4xx/401 rate | A spike may mean a client bug or credential stuffing. |
| Latency (p95) | Catches database contention. |
| MySQL availability & connections | The API cannot serve without it. |
| Disk usage | Unbounded logs (if `LOG_STACK=single`) and MySQL growth fill disks. |
| CPU / memory | PHP-FPM and Node process health. |
| TLS certificate expiry | Outage when it lapses. |

No third-party monitoring service (Sentry, etc.) is configured. Adding one is a
deliberate choice, not a requirement of this codebase.

---

## 11. Health check

```
GET /api/v1/health   →   200 {"success":true,"message":"API is healthy","data":{"status":"ok"}}
```

**Public, unauthenticated, and unchanged by this phase.** `HealthController`
returns a static payload with **no database query and no business logic**, so it
stays cheap enough to poll frequently. It deliberately does *not* run reports or
aggregate queries.

---

## 12. Web server / reverse proxy

The application ships **no** nginx/Apache configuration. Provide your own, and
route:

* `https://<api-host>/api/*` → PHP-FPM (Laravel's `public/` document root).
* `https://<frontend-host>/*` → the Node/Next.js server.
* The two are **different origins**, which is why CORS configuration is required.

Two things people get wrong:

1. **Document root must be `backend/public/`.** Pointing it at the project root
   exposes `.env` and `storage/` as plain files.
2. **Set `TRUSTED_PROXIES`** if you terminate TLS in the proxy (see §5).

---

## 13. Pre-flight checklist

- [ ] Backup taken and **restore-tested** (§9)
- [ ] `composer install --no-dev --optimize-autoloader` completed
- [ ] `.env` created from `.env.example`; `APP_KEY` generated
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<api-host>`
- [ ] MySQL database + least-privilege user created
- [ ] `php artisan migrate --force`
- [ ] `php artisan db:seed --force` (roles & permissions)
- [ ] `storage/` and `bootstrap/cache/` writable by the web user (no `777`)
- [ ] `CORS_ALLOWED_ORIGINS=https://<frontend-host>` (exact origin, not `*`)
- [ ] `TRUSTED_PROXIES` set **iff** behind a reverse proxy
- [ ] TLS certificate valid; HTTPS enforced
- [ ] `config:cache`, `route:cache`, `view:cache` run (after `.env` is final)
- [ ] `NEXT_PUBLIC_API_BASE_URL=https://<api-host>/api/v1` set at build time
- [ ] `npm ci && npm run build` succeeded
- [ ] Document root is `backend/public/`
- [ ] `GET /api/v1/health` returns 200 over HTTPS
- [ ] Smoke test (§14) passed
- [ ] Rollback path understood and backup location confirmed (§15)

---

## 14. Production smoke test

Run against a **staging or pre-production database first**. Several steps write
real records; do **not** run them destructively against live production data.

**AUTH**
- [ ] `POST /api/v1/auth/login` with valid credentials → 200 + token
- [ ] `GET /api/v1/auth/me` with that token → 200 + user profile
- [ ] `POST /api/v1/auth/logout` → 200; the token is then rejected (401)
- [ ] `GET /api/v1/auth/me` **without** a token → 401
- [ ] Login with a wrong password → 401, no detail leaked
- [ ] A suspended account is refused even with a valid token

**PRODUCT / INVENTORY**
- [ ] `GET /api/v1/products` → 200, paginated
- [ ] `GET /api/v1/products?search=<term>` → filters correctly
- [ ] Stock quantity shown per product
- [ ] `GET /api/v1/categories` → 200

**CUSTOMER / SUPPLIER**
- [ ] `GET /api/v1/customers` → 200
- [ ] Create a customer → 201 (if the role permits)
- [ ] `GET /api/v1/suppliers` → 200

**PURCHASE**
- [ ] Create a purchase for a supplier with line items
- [ ] **Verify stock INCREASED** by the purchased quantity
- [ ] A stock-movement / ledger entry was recorded

**POS (checkout)**
- [ ] Search a product from the POS screen
- [ ] Add to cart; totals recompute correctly
- [ ] Checkout with cash and/or another payment method

---

## 15. Rollback plan

> **Do not blindly run `migrate:rollback` in production.** Rolling a migration
> back is frequently more destructive than leaving it in place, and it does not
> undo data written after the migration ran.

**Before deploying, always have a verified backup** (§9). Treat it as mandatory,
not optional — at least one migration in this project is not cleanly reversible
(the `business_id` backfill on `roles`, which by design refuses to guess
ownership).

**Application rollback (safe, preferred):**

1. Redeploy the previous release artifact (previous commit / build).
2. Do **not** roll the database back.
3. Confirm the previous code is compatible with the current schema.

**Schema rollback (use only when necessary and understood):**

* Only if the new code genuinely cannot run against the migrated schema.
* Prefer restoring from backup over `migrate:rollback` whenever data has been
  written since the migration.
* If you do roll back, take a fresh backup first, then re-verify with the health
  check and the smoke test in §14.

**Frontend/backend version compatibility:**

* The API is versioned in the URL (`/api/v1`). A `v1` deployment and a `v1`
  frontend are contract-compatible; adding endpoints is safe.
* Breaking changes require a **new** `v2` route group — never mutate `v1` in
  place, or a frontend rollback could break against a newer API.
* The frontend's token handling and response envelope
  (`success` / `message` / `errors` / `data`) are part of the contract. Rolling
  the frontend back to an older build against a newer API is safe as long as the
  envelope is unchanged.

---

## 16. Verification status

Verified in this repository (Windows dev environment, PHP 8.2.33, Node 20.20.2):

| Gate | Result |
| --- | --- |
| `php artisan test` | **291 passed**, 1559 assertions, 0 failures |
| `npx tsc --noEmit` | **0 errors** |
| `npx eslint .` | **0 errors**, 2 pre-existing warnings |
| `npm run build` | **Succeeds** (exit 0), no source maps emitted |
| `php artisan route:list` | 78 lines, all routes resolve |
| `php artisan migrate:status` | All 28 migrations present |
| `config:cache` / `route:cache` / `view:cache` | All succeed; revert cleanly via `optimize:clear` |

Passing tests do **not** make the application production-ready. The items in
§§ 1–15 are environment-dependent and require the actual infrastructure to
complete.

**Known non-blocking warning:** ESLint reports 2
`react-hooks/incompatible-library` warnings (React Hook Form's `watch()` /
`useForm()` API, which React Compiler skips memoizing). These are informational,
pre-existing, and do not affect the build or runtime behaviour.

- [ ] **Verify stock DECREASED** by the sold quantity
- [ ] The sale and its payment record exist; receipt renders

**RETURN**
- [ ] Create a return against a valid completed sale
- [ ] **Verify stock was RESTORED** by the returned quantity
- [ ] Returning more than was sold is rejected

**EXPENSE**
- [ ] Create an expense with a category and payment method → 201
- [ ] The expense appears in the expense list

**CASH REGISTER**
- [ ] Open a register with a starting float
- [ ] Record a cash transaction (sale / pay-in / pay-out)
- [ ] Close the register; summary matches the expected cash
- [ ] Closing with a missing or incorrect float is rejected

**REPORT**
- [ ] Dashboard / report endpoint → 200 with figures
- [ ] Sales totals reconcile with the sales list for the same period

**USERS / ROLES / PERMISSIONS**
- [ ] A user without a permission receives 403 on that endpoint
- [ ] A user **can** perform the action their role allows
- [ ] Role assignment works; an administrator cannot escalate their own permissions

**SETTINGS**
- [ ] `GET /api/v1/settings` → 200
- [ ] `PUT`/`PATCH` settings persists and is reflected on the next read

**TENANCY (critical)**
- [ ] Create business A and business B with their own users and products
- [ ] A user of business A requesting a business B record → **404**
- [ ] A list endpoint never returns the other business's rows
- [ ] A client-supplied `business_id` is ignored (server resolves it from the token)

**HEALTH**
- [ ] `GET /api/v1/health` → 200, no auth required


> Because it does not touch the database, a green health check means "the PHP
> process is serving requests" — **not** "the database is reachable". Monitor
> database availability separately (see §10).

Laravel also exposes `GET /up` (framework health route).


Verified during this phase:

* `npm run build` succeeds (exit 0) with no TypeScript errors.
* **No source maps are emitted** (0 `.map` files in the build output).
* The current `.env.local` is development-only, so a local build contains
  `http://localhost:8000/api/v1`. This is expected — the production build must be
  produced with the production `NEXT_PUBLIC_API_BASE_URL` set.

Deployment: run the Node server under a process supervisor (systemd, or pm2) and
put a reverse proxy in front for TLS and static caching. `next start` is not a
production-grade supervisor by itself.

default is not the one you get by accident.

### Cookies and Sanctum

* The API authenticates with **Sanctum bearer tokens** (`Authorization: Bearer …`).
  The frontend's Axios instance sets `withCredentials: false` on purpose: sending
  credentials would push the backend into Sanctum's SPA-cookie mode and produce
  HTTP 419 CSRF failures.
* `config/session.php` now defaults the session cookie's `secure` flag to `true`
  whenever `APP_ENV=production`, so a production deployment cannot accidentally
  ship a plaintext session cookie through an unset variable.
* `SANCTUM_STATEFUL_DOMAINS` only matters for Sanctum's SPA *cookie* flow, which
  this application does not enable. It is present for completeness only.
* The authentication architecture was **not** changed in this phase.

* `frontend/.env.development.local` and `frontend/.env.production` — ignored by
  `frontend/.gitignore` (`.env*` with `!.env.example`).
* Only `.env.example` files are committed. They contain **no real secrets** —
  `APP_KEY` is blank and `DB_PASSWORD` is empty.
* **If a secret is ever committed, rotate it.** Rewriting history does not
  un-publish a secret that was pushed. Rotate `APP_KEY` / DB credentials and
  invalidate the affected tokens.

---
