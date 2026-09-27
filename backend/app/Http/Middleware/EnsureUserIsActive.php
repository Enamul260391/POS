<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from accounts that are no longer active.
 *
 * Suspending a user (`users.status = 'inactive'`) has to take effect for
 * sessions that already exist, not only for the next login. Without this
 * middleware a deactivated cashier keeps full API access until their Sanctum
 * token is revoked or expires, because `auth:sanctum` only proves a token is
 * valid — it never re-checks the account behind it.
 *
 * Registered *after* `auth:sanctum` on the protected route groups, so the user
 * is guaranteed to be present. When the check fails the current token is
 * deleted before the 401 is thrown: a suspended account must not be able to
 * keep using a token it already holds, and deleting it is the only way to make
 * that stick for stateless bearer auth (there is no session to invalidate).
 *
 * A missing or `null` status counts as active so an installation whose column
 * has not been backfilled is never locked out; only an explicit `inactive` is
 * rejected.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Defensive: the middleware is only meaningful behind `auth:sanctum`.
        if (! $user instanceof User) {
            return $next($request);
        }

        // The status is re-read from the database rather than taken from the
        // hydrated model attribute. The instance resolved by `auth:sanctum` is
        // whatever was loaded when the token was validated, so a suspension
        // committed after that point would not be visible on it — and a stale
        // `active` would keep the session alive. One indexed primary-key lookup
        // is the price of making the check authoritative.
        $status = User::query()
            ->whereKey($user->getKey())
            ->value('status');

        // A missing user (deleted mid-session) or a null/unknown status counts
        // as active, so an installation whose column has not been backfilled is
        // never locked out; only an explicit `inactive` is refused.
        if ($status !== User::STATUS_INACTIVE) {
            return $next($request);
        }

        // Revoke the token that just proved the account is suspended, so the
        // next attempt fails at `auth:sanctum` instead of reaching here again.
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        throw new AuthenticationException('Unauthenticated.');
    }
}
