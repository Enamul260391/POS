<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * ---------------------------------------------------------------------------
 * Realtime channel authorization (Phase RT-1).
 *
 * This file is the **security boundary** for the whole realtime layer. Reverb
 * will not accept a `private-*` subscription until the API has signed it, and
 * the only place that decision is made is the callback below. Anything that
 * trusts the frontend here is worthless: the browser picks the channel name it
 * asks for, so `business.7` is just as easy to type as `business.1`.
 *
 * The rule is therefore absolute and one-directional:
 *
 *     a user may subscribe to their OWN business' channel, and no other.
 *
 * The comparison is against `$user->business_id` — read from the database row
 * behind the Sanctum token — and never against anything the request supplied.
 * That is what makes it a genuine boundary rather than a UI filter: a Business
 * A user requesting `business.7` is refused by Reverb, so the socket never
 * receives a single byte of Business B's product or stock data. Frontend
 * filtering is a usability nicety on top, never the protection.
 *
 * A null `business_id` (a user with no tenant) matches no channel, because
 * `(int) null === 0` and no real business has id 0.
 *
 * The Platform Owner is refused *explicitly* rather than through that
 * coercion: it is a platform identity (`User::isPlatformOwner()`), never a
 * tenant, so it must not subscribe even to the degenerate `business.0`
 * channel name. The tenant rule itself is unchanged.
 * ---------------------------------------------------------------------------
 */
Broadcast::channel('business.{businessId}', function (User $user, int $businessId): bool {
    if ($user->isPlatformOwner()) {
        return false;
    }

    return (int) $user->business_id === $businessId;
});

