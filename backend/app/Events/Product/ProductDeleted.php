<?php

declare(strict_types=1);

namespace App\Events\Product;

use App\Support\BusinessChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A product was soft-deleted and left the catalogue.
 *
 * Carries only the identity of what disappeared. The event is a notification,
 * not a data source: a client removes the row from its local collection and the
 * REST API remains authoritative for whether the product really is gone.
 *
 * Deleting a product is *not* declared here to mean anything about carts or
 * open baskets. The backend has no rule for "a product in a cart was deleted",
 * and inventing one would be a business decision, not a realtime one.
 */
class ProductDeleted implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $businessId  Owning business, taken from the stored product.
     * @param  int  $productId  Id of the product that was deleted.
     */
    public function __construct(
        public readonly int $businessId,
        public readonly int $productId,
    ) {}

    /**
     * Channels the event is broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [BusinessChannel::forBusiness($this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'product.deleted';
    }

    /**
     * The wire payload.
     *
     * @return array{business_id: int, product_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
        ];
    }
}
