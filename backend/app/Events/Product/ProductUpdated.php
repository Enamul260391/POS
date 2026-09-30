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
 * A product's master data changed.
 *
 * Like {@see ProductCreated} the payload is only a pointer (`product_id` plus
 * the owning `business_id`): the client re-reads `GET /products/{id}` for the
 * authoritative row. Master data is small but not trivial, and a rename or a
 * price change must never be applied from a broadcast that could be a stale
 * replay of an older state.
 *
 * Broadcast after commit only, and synchronously (no queue worker exists).
 */
class ProductUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $businessId  Owning business, taken from the stored product.
     * @param  int  $productId  Id of the product that changed.
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
        return 'product.updated';
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
