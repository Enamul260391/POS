<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Single definition of the business-scoped realtime channel.
 *
 * Every realtime event in the application is delivered on exactly one channel
 * shape — `private-business.{businessId}` — so there is a single place that
 * knows how a tenant's private channel is named. The frontend subscribes with
 * the same string, and the authorization callback lives in `routes/channels.php`
 * (which deliberately does *not* come through here: a channel callback receives
 * the authenticated user, this helper only builds the name).
 *
 * Why a private channel and not a public one: product and stock data is tenant
 * business data. A public channel would hand any socket that guessed the name
 * every product name, price and stock balance of a store. `PrivateChannel`
 * makes Reverb require a signed `auth` response from `routes/channels.php`
 * before the subscription is accepted, and that callback compares the channel's
 * business id against the *authenticated user's* business — a value the browser
 * can never influence.
 */
final class BusinessChannel
{
    /**
     * The private channel carrying a business's realtime events.
     *
     * `business_id` is always the value the backend resolved from stored state
     * (the product's, or the document's, own `business_id`) — never a value
     * supplied by the request.
     */
    public static function forBusiness(int $businessId): PrivateChannel
    {
        return new PrivateChannel('business.'.$businessId);
    }
}
