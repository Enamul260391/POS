<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Derives the tenant scope for a request from the authenticated user.
 *
 * `business_id` is never accepted from the client: every Phase 20 endpoint
 * takes it from here, and every service call receives it as an explicit
 * argument (or reads it from a resolved model that was already scoped).
 */
trait ResolvesBusiness
{
    /**
     * Business the authenticated user belongs to.
     *
     * Routes using this trait sit behind `auth:sanctum`, so a user is always
     * present; the null-safe fallback only keeps static analysis honest.
     */
    protected function businessId(): int
    {
        return (int) $this->user()?->business_id;
    }

    /**
     * Primary key of the authenticated user, used as the audit trail.
     */
    protected function userId(): ?int
    {
        return $this->user()?->getKey();
    }
}
