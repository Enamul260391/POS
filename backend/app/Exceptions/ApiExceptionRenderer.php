<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps exceptions raised while handling API requests onto the standard error
 * envelope. Registered from `bootstrap/app.php` and used nowhere else.
 *
 * Two entry points:
 *  - {@see render()} handles every non-`Responsable` exception (the common path).
 *  - {@see normalize()} is the safety net for responses Laravel rendered itself
 *    (custom `render()` methods, third-party `Responsable` exceptions).
 *
 * Business rules raised by the service layer travel as
 * {@see BusinessRuleException}; they keep their own wording and status code
 * because the service owns the domain language.
 *
 * Web requests are never touched: both entry points return early unless the
 * request targets the API (or explicitly asks for JSON), which keeps Laravel's
 * normal HTML error pages intact.
 *
 * Security: responses contain a canonical message per status code only. The
 * exception message, stack trace, SQL and file paths are never echoed to the
 * client — the full exception is still reported through Laravel's logger.
 */
final class ApiExceptionRenderer
{
    /**
     * Canonical client-facing message per HTTP status code.
     *
     * Business-specific messages are not produced here; services/controllers
     * return those with {@see ApiResponse::error()} because they own the domain
     * wording, and the code keeps a single safe default per status.
     */
    private const MESSAGES = [
        400 => 'Bad request',
        401 => 'Unauthenticated',
        403 => 'Forbidden',
        404 => 'Resource not found',
        405 => 'Method not allowed',
        409 => 'Conflict',
        419 => 'Page expired',
        422 => ApiResponse::VALIDATION_FAILED,
        429 => 'Too many requests',
        500 => ApiResponse::SOMETHING_WENT_WRONG,
    ];

    /**
     * Determine whether a request belongs to the JSON API.
     *
     * Everything under `/api/*` is API traffic; any other route is only treated
     * as an API consumer when the client asked for `Accept: application/json`.
     */
    public static function isApiRequest(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Render an exception as the standard API error envelope.
     *
     * Returns null for non-API requests so Laravel renders web errors normally.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::isApiRequest($request)) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::validation($e->errors());
        }

        // Business rules that depend on stored state cannot be expressed as
        // field validation. The service layer owns that wording and the status
        // code, so pass them through instead of replacing them with the
        // canonical per-status default (which happens for every other
        // exception).
        if ($e instanceof BusinessRuleException) {
            return ApiResponse::error($e->getMessage(), [], $e->status());
        }

        $status = self::status($e);

        return ApiResponse::error(self::message($e, $status), [], $status);
    }

    /**
     * Normalise an already-rendered API error response into the envelope.
     *
     * Responses that already follow the contract, deliberately authored
     * responses (`HttpResponseException`) and web responses are returned
     * untouched.
     */
    public static function normalize(Response $response, Throwable $e, Request $request): Response
    {
        if (! self::isApiRequest($request)
            || $e instanceof HttpResponseException
            || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (is_array($payload) && array_key_exists('success', $payload)) {
            return $response;
        }

        $status = $response->getStatusCode();

        return ApiResponse::error(self::message($e, $status), [], $status);
    }

    /**
     * Resolve the HTTP status code for an exception.
     */
    private static function status(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof NotFoundHttpException => 404,
            $e instanceof AuthorizationException => 403,
            $e instanceof AccessDeniedHttpException => 403,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }

    /**
     * Resolve the canonical client-facing message for an exception.
     */
    private static function message(Throwable $e, ?int $status = null): string
    {
        $status ??= self::status($e);

        return self::MESSAGES[$status]
            ?? Response::$statusTexts[$status]
            ?? ApiResponse::SOMETHING_WENT_WRONG;
    }
}
