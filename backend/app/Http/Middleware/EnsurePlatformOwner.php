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
 * Gate for the platform boundary: only the Platform Owner may pass.
 *
 * The check is a single, explicit identity test —
 * {@see User::isPlatformOwner()}. It deliberately does NOT infer platform
 * identity from `business_id = null`, email, id, role names or permissions:
 * those are all properties a normal account could theoretically share, while
 * `platform_role = owner` is the one marker Step 1 established for this
 * identity (and the database allows at most one row to hold it).
 *
 * Registered behind `auth:sanctum` + `active` (alias `platform` in
 * `bootstrap/app.php`), so a user is always present here; the null guard is
 * defensive only. Refusals are deny-by-default:
 *
 *   - no authenticated user  -> 401 Unauthenticated
 *   - authenticated non-owner -> 403 Forbidden
 *
 * This middleware is intentionally small and identity-focused. It contains
 * no business CRUD logic — endpoints behind it authorise the individual
 * platform abilities (`platform.businesses.*` Gates) themselves.
 */
final class EnsurePlatformOwner
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

        if (! $user->isPlatformOwner()) {
            throw new AuthorizationException('Only the platform owner may perform this action.');
        }

        return $next($request);
    }
}