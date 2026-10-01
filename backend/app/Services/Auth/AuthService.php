<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Handles authentication logic for the POS API.
 *
 * Responsibilities:
 *  - Authenticate a user by email + password and issue a Sanctum token.
 *  - Enforce the single-active-login rule (one live token per user account).
 *  - Revoke the current access token (logout).
 *  - Retrieve the authenticated user for the "me" endpoint.
 *
 * Controllers stay thin by delegating all auth logic here.
 */
class AuthService
{
    /**
     * Name given to the Sanctum token minted by a successful login.
     *
     * Kept as a single constant so the identifier recorded in
     * `personal_access_tokens.name` is consistent across the application.
     */
    public const TOKEN_NAME = 'pos-api-token';

    /**
     * Authenticate the user and create a Sanctum API token.
     *
     * Returns `null` for every kind of failure — unknown email, wrong password
     * and a suspended account alike — so the endpoint never becomes a probe for
     * which addresses are registered. Callers must render a single generic
     * message for all three.
     *
     * Successful logins are **single-login**: an account may hold at most one
     * live Sanctum token. Every token the user already owns is revoked before
     * the new one is minted, so signing in from a second till/PC immediately
     * signs the first one out. The revocation is scoped to the authenticated
     * user's own token relationship, so it can never touch another account —
     * not even one inside the same business.
     *
     * @return array{token: string, token_type: string, user: User}|null
     */
    public function login(string $email, string $password): ?array
    {
        // Look up the user with roles eager-loaded to avoid N+1 in the resource.
        // `permissions` is not eager-loaded: it is a memoised accessor rather
        // than a relation, so `with()` cannot hydrate it. Calling it here
        // populates the cache that `UserResource` and the Gate then share.
        $user = User::with('roles')
            ->where('email', $email)
            ->first();

        // Do not reveal whether the email or password was incorrect.
        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        // A suspended account keeps its row (and its password hash) so the audit
        // trail stays intact, but it must not be able to mint a new token. The
        // check sits *after* the password verification so a suspended user is
        // not distinguishable from an unknown one by response timing either.
        //
        // It also deliberately runs *before* the revocation below: a refused
        // login must leave every existing token exactly as it was, otherwise a
        // suspended account could be used to knock a live session offline.
        if (! $this->isActive($user)) {
            return null;
        }

        $token = $this->issueSoleToken($user);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }

    /**
     * Revoke every existing token of this user, then mint exactly one new one.
     *
     * Ordering matters: the old sessions must already be gone by the time the
     * response is returned, otherwise a brief window exists where the account
     * holds two live tokens.
     *
     * Both statements run in one transaction, and the user row is re-read with
     * `lockForUpdate` first. Without that lock two simultaneous logins can both
     * pass the `DELETE` before either has inserted its token, leaving two valid
     * sessions behind; serialising on the user row makes the second one wait,
     * and it then deletes the token the first one just created. The lock is a
     * plain row lock on the user's own primary key — no Redis, cache or
     * external coordinator is introduced.
     *
     * The delete is deliberately `$user->tokens()`, i.e. the polymorphic
     * relation keyed on `tokenable_type`/`tokenable_id`. It is therefore scoped
     * to this one account and is independent of `business_id`, email, IP or
     * device: no other user's tokens are ever candidates for deletion.
     */
    private function issueSoleToken(User $user): NewAccessToken
    {
        return DB::transaction(function () use ($user): NewAccessToken {
            // Serialise concurrent logins for this account. Re-read inside the
            // transaction so the lock is taken on the connection that will also
            // run the delete and the insert.
            $locked = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            // A user deleted between the lookup above and this transaction
            // cannot be logged in; `first()` returning null leaves us with
            // nothing to issue a token for.
            if ($locked === null) {
                throw new AuthenticationException('Unauthenticated.');
            }

            // Revoke the previous device(s)...
            $user->tokens()->delete();

            // ...and hand this device the only live session.
            return $user->createToken(self::TOKEN_NAME);
        });
    }

    /**
     * May this account hold a session?
     *
     * A missing/`null` status is treated as active so an installation whose
     * `users.status` column has not been backfilled yet is not locked out; the
     * column defaults to `active`, and only an explicit `inactive` is honoured.
     */
    public function isActive(User $user): bool
    {
        return $user->status !== User::STATUS_INACTIVE;
    }

    /**
     * Revoke only the current access token used for this request.
     *
     * If the guard is ever session-backed (Sanctum's TransientToken), there is
     * no persisted token to delete — only real personal access tokens are
     * revoked, so logout never touches tokens from other devices/sessions.
     *
     * Note this is intentionally *not* symmetric with the single-login rule:
     * signing out should end exactly the session that asked to sign out and
     * must never invalidate an unrelated account, so only the presented token
     * is deleted here.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
 * Reload the authenticated user (with roles) for the "me" endpoint.
     *
     * `permissions` is deliberately *not* eager-loaded. It is a plain accessor
     * returning an already-built collection, not an Eloquent relation, so
     * `load()` cannot use it and would fail with `addEagerConstraints`. Calling
     * it once below is what matters: `UserResource` then reads the memoised
     * relation, so the capability list costs a single query and every later
     * `permissionSlugs()` check reuses it.
     */
    public function getCurrentUser(User $user): User
    {
        $user->load('roles');

        $user->permissions();

        return $user;
    }
}
