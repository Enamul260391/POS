<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Health-check endpoint.
 *
 * Returns a minimal status payload independent of any business logic or
 * database query, so load balancers and monitoring can probe it cheaply.
 */
class HealthController extends Controller
{
    /**
     * Handle the health-check request.
     */
    public function __invoke(): JsonResponse
    {
        return $this->success(
            data: ['status' => 'ok'],
            message: 'API is healthy',
        );
    }
}
