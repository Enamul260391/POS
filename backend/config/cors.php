<?php

/**
 * --------------------------------------------------------------------------
 * Cross-Origin Resource Sharing (CORS) configuration
 * --------------------------------------------------------------------------
 *
 * Laravel's built-in HandleCors middleware (Illuminate\Http\Middleware\HandleCors)
 * reads this file. It is intentionally environment-driven so that the
 * development frontend (http://localhost:3000) and any future production domain
 * are configured via env vars — no hardcoded hosts in code.
 *
 * `supports_credentials` is true so that Sanctum SPA-cookie authentication
 * (future phase) can send cookies cross-origin. When credentials are enabled,
 * `allowed_origins` must be an explicit list (wildcards are rejected by the
 * browser).
 */

return [

    // Paths the CORS service applies to. API routes and the Sanctum
    // CSRF-cookie endpoint both need to be covered.
    'paths' => ['api/*', 'sanctum/csrf_cookie'],

    // HTTP verbs allowed for cross-origin requests.
    'allowed_methods' => ['*'],

    // Comma-separated list of trusted frontend origins, e.g.
    //   .env  → CORS_ALLOWED_ORIGINS=http://localhost:3000
    //   prod  → CORS_ALLOWED_ORIGINS=https://<your-frontend-host>
    //
    // The list is trimmed and empty entries are discarded so a trailing comma
    // or a stray space cannot silently turn into a blank origin.
    'allowed_origins' => (static function (): array {
        // A loopback origin is only ever meaningful on a developer's machine: no
        // real browser sends `Origin: http://localhost:3000` to a public site. An
        // entry with no parseable host (a bare "example.com", a stray token left
        // over from a bad edit) cannot be proven to be a real origin either, so it
        // counts as untrusted here.
        $isLoopbackOrigin = static function (string $origin): bool {
            $host = parse_url($origin, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                return true;
            }

            $host = strtolower(trim($host, '[]'));

            return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
                || str_ends_with($host, '.localhost');
        };

        $configured = (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000');

        $origins = array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $origin): bool => $origin !== '',
        ));

        // A wildcard origin is rejected outright: `supports_credentials` is
        // enabled below, and the CORS spec forbids combining `*` with
        // credentials. A deployment that sets CORS_ALLOWED_ORIGINS=* would
        // otherwise fail at the browser with a confusing CORS error and no
        // server-side indication of the misconfiguration. Failing loudly at
        // boot is far easier to diagnose than a silently broken frontend.
        if (in_array('*', $origins, true)) {
            throw new RuntimeException(
                'CORS_ALLOWED_ORIGINS may not contain "*" because config/cors.php enables '
                .'supports_credentials. List the explicit frontend origin(s) instead, '
                .'e.g. CORS_ALLOWED_ORIGINS=https://your-frontend-host.',
            );
        }

        // The specific failure this guards against: a production deployment that
        // kept the `http://localhost:3000` development default. Every preflight
        // is then answered with `Access-Control-Allow-Origin: http://localhost:3000`,
        // which the real frontend origin never matches, so the browser reports a
        // CORS error instead of the HTTP status the API actually returned — the
        // login screen looks broken while the API is perfectly healthy. There is
        // no safe fallback for this case, so the only correct action is to refuse
        // to boot and name the variable to set.
        if (env('APP_ENV') === 'production') {
            $hasRealOrigin = (static function (array $origins) use ($isLoopbackOrigin): bool {
                foreach ($origins as $origin) {
                    if (! $isLoopbackOrigin($origin)) {
                        return true;
                    }
                }

                return false;
            })($origins);

            if (! $hasRealOrigin) {
                throw new RuntimeException(
                    'CORS_ALLOWED_ORIGINS lists no real frontend origin while APP_ENV=production. '
                    .'A browser sends its own origin (e.g. https://pos.example.com), which never '
                    .'matches a localhost entry, so every cross-origin request — login included — is '
                    .'blocked before it reaches the controller. Set CORS_ALLOWED_ORIGINS to the exact '
                    .'production frontend origin(s), comma-separated, '
                    .'e.g. CORS_ALLOWED_ORIGINS=https://pos.example.com.',
                );
            }
        }

        return $origins;
    })(),

    // Reflects the request's Origin header on allowed origins. Set to a
    // comma-separated list to restrict further.
    'allowed_origins_patterns' => [],

    // Headers the browser may send. Wildcard is fine for our API.
    'allowed_headers' => ['*'],

    // Headers the browser is allowed to read in the response.
    'exposed_headers' => [],

    // How long the browser may cache the preflight response.
    'max_age' => 0,

    // Must be true for Sanctum cookie auth over cross-origin requests.
    'supports_credentials' => true,

];
