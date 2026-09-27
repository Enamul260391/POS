<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Business;
use App\Models\User;

/**
 * Authentication helpers for Phase 20 feature tests.
 *
 * `actingAsBusinessUser()` creates a user inside the given business (a fresh
 * business when none is supplied) and installs a real Sanctum bearer token as
 * the default header, so every subsequent request in the test authenticates
 * exactly like production traffic.
 *
 * `headersFor()` produces per-request headers for a second identity without
 * replacing the default one — per-call headers win over the defaults in
 * Laravel's test request pipeline, which lets a single test act as two
 * tenants (e.g. to prove cross-business access returns 404).
 */
trait InteractsWithPosApi
{
    /**
     * Create a user in the given (or a new) business and authenticate as them
     * for the rest of the test.
     */
    protected function actingAsBusinessUser(?Business $business = null): User
    {
        $business ??= Business::factory()->create();

        $user = User::factory()->create(['business_id' => $business->id]);

        $this->withToken($user->createToken('feature-test')->plainTextToken);

        return $user;
    }

    /**
     * Authorization headers authenticating as the given user, applied to a
     * single request only.
     *
     * @return array<string, string>
     */
    protected function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('feature-test')->plainTextToken,
        ];
    }
}
