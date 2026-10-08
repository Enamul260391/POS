<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep tenant operational routes on tenant identities only.
 *
 * This is the *explicit* counterpart to the accidental protection the system
 * relied on until now: a Platform Owner has `business_id = NULL`, so tenant
 * permission resolution happened to yield nothing and every `can:` check
 * failed as a side effect. That is too fragile for a security boundary — a
 * future resolver change could silently widen it — so every operational
 * route group now carries this alias and refuses the platform identity up
 * front, before any capability check, controller or service runs.
 *
 * Registered behind `auth:sanctum` + `active` (alias `business` in
 * `bootstrap/app.php`), so a user is always present here; the null guard is
 * defensive only. The rule is exactly two conditions:
 *
 *   - the authenticated user must NOT be the Platform Owner
 *     (`User::isPlatformOwner()` — the explicit Step 1 marker), and
 *   - the authenticated user must have a non-null `business_id`.
 *
 * Anything else is a refusal (401 without a user, 403 otherwise) — deny by
 * default.
 *
 * Deliberately NOT this middleware's job: checking whether the business is
 * active or soft-deleted, resolving capabilities (`can:` does that), or any
 * tenant scoping (the services own that). Those belong to later steps;
 * keeping this middleware identity-only is what stops the boundary from
 * growing into a second authorization system.
 */
final class EnsureBusinessUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Defensive: the alias is only meaningful behind `auth:sanctum`, which
        // has already answered 401 for an anonymous caller. Fail closed anyway
        // so the boundary never depends on middleware ordering.
        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        // Platform Owner first: it is a platform identity by design and must
        // never reach a tenant controller — not even one that would later 403
        // on a capability, because the denial has to happen before any tenant
        // logic executes.
        if ($user->isPlatformOwner() || $user->business_id === null) {
            throw new AuthorizationException('A business membership is required for this action.');
        }

        return $next($request);
    }
}