<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Support\LoginThrottle;
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
     *
     * Failed-sign-in accounting lives here rather than in a `throttle:`
     * middleware, because only this method knows whether the attempt actually
     * failed. See {@see LoginThrottle} for why a middleware limiter locks out
     * legitimate cashiers.
     *
     * The order matters:
     *
     *   1. lockout check  — before any password hashing, so a locked-out caller
     *                      cannot burn server CPU with bcrypt
     *   2. authenticate   — {@see AuthService} answers `null` for every failure
     *   3. record         — a failure hits both buckets; a success clears them
     *
     * The address and IP are read once and reused, so the key that is checked
     * is provably the same key that is later recorded against.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->input('email');
        $ip = (string) $request->ip();

        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            return LoginThrottle::tooManyAttemptsResponse($email, $ip);
        }

        $result = $this->authService->login(
            $email,
            (string) $request->input('password'),
        );

        if (! $result) {
            // Every refusal is counted identically — unknown address, wrong
            // password and suspended account alike — so the endpoint cannot be
            // used to discover which addresses are registered.
            LoginThrottle::recordFailure($email, $ip);

            return $this->error('Invalid credentials', [], 401);
        }

        // A correct password proves the earlier failures were not an attack, so
        // the budget is restored and the next sign-in is not throttled.
        LoginThrottle::recordSuccess($email, $ip);

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
