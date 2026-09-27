<?php

namespace App\Http\Traits;

use App\Support\ApiResponse as ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Controller-facing entry point to the standard API response envelope.
 *
 * Controllers extending the base Controller use it as:
 *
 *   return $this->success(new UserResource($user), 'Authenticated user');
 *   return $this->error('Invalid credentials', [], 401);
 *   return $this->paginated($products, 'Products retrieved successfully', ProductResource::class);
 *
 * The envelope itself is assembled by the single implementation in
 * {@see ResponseFactory} — this trait only exposes it as `$this->` helpers, so
 * controllers never hand-build JSON and the contract cannot drift between
 * endpoints.
 */
trait ApiResponse
{
    /**
     * Build a success response.
     *
     * @param  mixed  $data  Payload for the `data` field (array, JsonResource, or null).
     * @param  string  $message  Human-readable message.
     * @param  int  $status  HTTP status code (200, or 201 for a created resource).
     */
    protected function success(
        mixed $data = null,
        string $message = ResponseFactory::SUCCESS,
        int $status = 200,
    ): JsonResponse {
        return ResponseFactory::success($data, $message, $status);
    }

    /**
     * Build an error response.
     *
     * @param  string  $message  Human-readable message (never internal details).
     * @param  array<string, mixed>  $errors  Field-level error bag, empty when there is none.
     * @param  int  $status  HTTP status code.
     */
    protected function error(
        string $message = ResponseFactory::SOMETHING_WENT_WRONG,
        array $errors = [],
        int $status = 400,
    ): JsonResponse {
        return ResponseFactory::error($message, $errors, $status);
    }

    /**
     * Build a 422 validation failure response.
     *
     * @param  array<string, array<int, string>>  $errors  Laravel's validation error bag.
     * @param  string  $message  Human-readable message.
     */
    protected function validation(
        array $errors,
        string $message = ResponseFactory::VALIDATION_FAILED,
    ): JsonResponse {
        return ResponseFactory::validation($errors, $message);
    }

    /**
     * Build a paginated success response (`data.items` + `data.pagination`).
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator  Paginator returned by the query.
     * @param  string  $message  Human-readable message.
     * @param  class-string<JsonResource>|null  $resource  Resource used to shape each item.
     * @param  int  $status  HTTP status code.
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        string $message = ResponseFactory::SUCCESS,
        ?string $resource = null,
        int $status = 200,
    ): JsonResponse {
        return ResponseFactory::paginated($paginator, $message, $resource, $status);
    }
}
