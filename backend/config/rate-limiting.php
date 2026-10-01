<?php

/**
 * ---------------------------------------------------------------------------
 * API rate limiting
 * ---------------------------------------------------------------------------
 *
 * Consumed by App\Providers\AppServiceProvider (the `api` limiter that
 * bootstrap/app.php prepends to the api middleware group) and by
 * App\Support\LoginThrottle (the failed-sign-in lockout on
 * POST /api/v1/auth/login).
 *
 * Environment-driven so an installation can be tuned from `.env` — and only
 * from `.env` — instead of by editing PHP. No Redis is required: both limiters
 * use the default cache store (`file` or `database`).
 *
 * The two are deliberately different in kind:
 *
 *  - `api` is a *flood guard*. Every request counts, successful or not. It is
 *    generous on purpose; it exists to stop a runaway client, not a human.
 *
 *  - `login` is a *credential-guessing guard*. Only FAILED sign-ins count, and
 *    a success clears the counter. A cashier who mistypes a password a few
 *    times is slowed down; a cashier who signs in correctly, or switches
 *    accounts at the till, is never locked out.
 * ---------------------------------------------------------------------------
 */

return [

    /*
    |--------------------------------------------------------------------------
    | General API flood guard (`throttle:api`)
    |--------------------------------------------------------------------------
    |
    | Keyed by the authenticated user id, falling back to the client IP for
    | public endpoints (login, health, broadcasting/auth). Generous by design:
    | it must not get in the way of normal POS usage.
    |
    | PRODUCTION: 120/minute is appropriate. Raise it if legitimate till
    | traffic ever trips it, but fix the client first — a real deployment that
    | needs more than this is polling something it should not.
    |
    */

    'api' => [
        'max_attempts' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
        'decay_minutes' => (int) env('API_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed sign-in lockout (`POST /api/v1/auth/login`)
    |--------------------------------------------------------------------------
    |
    | `account` is keyed by e-mail *and* IP: it stops an online guessing run
    | against one account without letting an attacker exhaust a real user's
    | budget from elsewhere.
    |
    | `ip` is keyed by IP alone: it is the backstop that makes e-mail rotation
    | useless, which a per-account key alone cannot do.
    |
    | PRODUCTION: the defaults below are intentional — 5 wrong passwords for
    | one account, 20 across the whole till, inside a 60 second window.
    |
    | Never raise `account.max_attempts` above 10, and never drop
    | `account.decay_seconds` below 30: those two together are what make an
    | online guessing attack impractical.
    |
    */

    'login' => [
        'account' => [
            'max_attempts' => (int) env('LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5),
            'decay_seconds' => (int) env('LOGIN_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        'ip' => [
            'max_attempts' => (int) env('LOGIN_IP_RATE_LIMIT_MAX_ATTEMPTS', 20),
            'decay_seconds' => (int) env('LOGIN_IP_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],

];
