<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles API token authentication endpoints.
 *
 * Endpoints:
 *  - POST /api/v1/auth/login  (public)
 *  - POST /api/v1/auth/logout (auth:sanctum)
 *  - GET  /api/v1/auth/me    (auth:sanctum)
 *
 * Controllers are thin adapters — validation lives in Form Requests,
 * business logic in AuthService, and response shaping in UserResource.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
    ) {}

    /**
     * Authenticate the user and return a Sanctum API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
        );

        if (! $result) {
            return $this->error('Invalid credentials', [], 401);
        }

        return $this->success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'user' => new UserResource($result['user']),
        ], 'Login successful');
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(null, 'Logout successful');
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->getCurrentUser($request->user());

        return $this->success(
            new UserResource($user),
            'Authenticated user',
        );
    }
}
