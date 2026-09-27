<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Single source of truth for the POS API JSON envelope.
 *
 * Success:
 *   { "success": true,  "message": "...", "data": {...} }
 *
 * Error:
 *   { "success": false, "message": "...", "errors": {...} }
 *
 * Every API response is assembled here — controllers use the
 * {@see \App\Http\Traits\ApiResponse} trait (which delegates to this class) and
 * the API exception renderer uses it directly. The envelope must never be
 * hand-built anywhere else, so a contract change is a one-file change.
 *
 * The shape matches what the Next.js Axios interceptor expects
 * (frontend/lib/axios.ts normalises `message` + `errors`).
 */
final class ApiResponse
{
    /** Default message for a successful response. */
    public const SUCCESS = 'Success';

    /** Default message for a 422 validation response. */
    public const VALIDATION_FAILED = 'Validation failed';

    /** Default message for an unexpected 500 response. */
    public const SOMETHING_WENT_WRONG = 'Something went wrong';

    /**
     * Build a success response.
     *
     * @param  mixed  $data  Payload for the `data` field (array, JsonResource, or null).
     * @param  string  $message  Human-readable message.
     * @param  int  $status  HTTP status code (200, or 201 for a created resource).
     */
    public static function success(
        mixed $data = null,
        string $message = self::SUCCESS,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Build an error response.
     *
     * @param  string  $message  Human-readable message (never internal details).
     * @param  array<string, mixed>  $errors  Field-level error bag, empty when there is none.
     * @param  int  $status  HTTP status code.
     */
    public static function error(
        string $message = self::SOMETHING_WENT_WRONG,
        array $errors = [],
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            // A non-empty bag has string keys and encodes as a JSON object
            // already; the empty case is cast so `errors` is always an object
            // on the wire (`{}`), never an array (`[]`).
            'errors' => $errors === [] ? (object) [] : $errors,
        ], $status);
    }

    /**
     * Build a 422 validation failure response.
     *
     * @param  array<string, array<int, string>>  $errors  Laravel's validation error bag.
     */
    public static function validation(
        array $errors,
        string $message = self::VALIDATION_FAILED,
    ): JsonResponse {
        return self::error($message, $errors, 422);
    }

    /**
     * Build a paginated success response.
     *
     * The list is always nested under `data.items` with the metadata in
     * `data.pagination` so list endpoints never drift apart:
     *
     *   { "success": true, "message": "...", "data": { "items": [], "pagination": {...} } }
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator  Paginator returned by the query.
     * @param  string  $message  Human-readable message.
     * @param  class-string<JsonResource>|null  $resource  Resource used to shape each item; raw models are never
     *                                                     returned to the client, so a resource should be supplied.
     * @param  int  $status  HTTP status code.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = self::SUCCESS,
        ?string $resource = null,
        int $status = 200,
    ): JsonResponse {
        $items = $resource
            ? $resource::collection($paginator->getCollection())->resolve()
            : $paginator->items();

        return self::success([
            'items' => array_values($items),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], $message, $status);
    }
}
