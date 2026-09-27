<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global CORS — applies to every request; the cors config gates which
        // paths / origins are actually allowed to through.
        $middleware->append([
            HandleCors::class,
        ]);

        // API rate limiting: prepends `throttle:api` to the api middleware
        // group. The `api` limiter itself is defined in AppServiceProvider so
        // there is a single place to tune API throughput. Redis throttling is
        // intentionally NOT enabled — no external infrastructure is required
        // for a single-instance POS deployment.
        $middleware->throttleApi('api');

        // The API middleware group keeps Laravel's defaults (throttling +
        // route model binding).
        //
        // Sanctum's EnsureFrontendRequestsAreStateful (SPA cookie auth) is
        // deliberately NOT enabled: this POS API authenticates with
        // `Authorization: Bearer {token}` only. That middleware starts a
        // session and enforces CSRF validation for any request carrying a
        // first-party Origin / Referer header, which makes browser calls to
        // the token endpoints fail with HTTP 419. SPA cookie auth belongs to a
        // later phase; config/sanctum.php and SANCTUM_STATEFUL_DOMAINS are
        // kept ready for it.

        // Reverse-proxy support. When the API runs behind nginx / Apache / a load
        // balancer, `Request::ip()` otherwise resolves to the *proxy's* address
        // instead of the real client, which silently breaks the per-IP keying of
        // the `api` and `login` rate limiters below: every user would share one
        // throttle bucket and a single typo would lock out the whole store.
        //
        // Deliberately opt-in and environment-driven: an unset (or blank)
        // TRUSTED_PROXIES trusts nothing, which is the correct default for local
        // development and for a directly exposed PHP-FPM. Production only needs
        // to list the address(es) of its own reverse proxy — e.g.
        // TRUSTED_PROXIES=127.0.0.1 or TRUSTED_PROXIES=10.0.0.1,10.0.0.2.
        // Never use `*` unless the application really is only reachable through
        // a proxy you control, because a spoofed X-Forwarded-For would then be
        // trusted for rate limiting.
        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(
                at: array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            );
        }

        // Suspended-account enforcement. `auth:sanctum` only proves a token is
        // still valid — it never re-reads `users.status` — so without this an
        // account deactivated after it logged in keeps working until the token
        // expires. Aliased here and applied to the protected route groups in
        // routes/api.php, where it always runs after `auth:sanctum`.
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ------------------------------------------------------------------
        // API-first exception handling
        // ------------------------------------------------------------------
        // All mapping lives in App\Exceptions\ApiExceptionRenderer so the
        // status-code / message convention has exactly one implementation:
        //
        //   ValidationException     -> 422 { success: false, message, errors }
        //   AuthenticationException -> 401 Unauthenticated
        //   AuthorizationException  -> 403 Forbidden
        //   NotFoundHttpException   -> 404 Resource not found
        //   HttpException           -> its own status (400, 405, 409, 429, ...)
        //   Throwable               -> 500 Something went wrong
        //
        // Stack traces, SQL, credentials, file paths and raw exception messages
        // are never returned to API clients; the full exception is still
        // reported through Laravel's logger.
        // ------------------------------------------------------------------

        // Only API traffic receives JSON errors, so web routes keep Laravel's
        // normal HTML error pages (and its debug page in local development).
        // Laravel calls this with ($request, $e); only the request matters here.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => ApiExceptionRenderer::isApiRequest($request)
        );

        // Handles every non-Responsable exception (the common path) and returns
        // null for web requests so Laravel renders those normally.
        $exceptions->renderable(
            static fn (Throwable $e, Request $request): ?JsonResponse => ApiExceptionRenderer::render($e, $request)
        );

        // Safety net: normalise any remaining API error response that Laravel
        // rendered itself (custom render() methods, Responsable exceptions), so
        // API clients never receive a non-standard JSON body. Responses already
        // in the envelope, deliberately authored responses and web responses
        // are returned untouched.
        $exceptions->respond(
            static fn (Response $response, Throwable $e, Request $request): Response => ApiExceptionRenderer::normalize($response, $e, $request)
        );
    })->create();
