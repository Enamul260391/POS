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
 * A product was added to a business' catalogue.
 *
 * Payload is deliberately minimal: the product id and the owning business id.
 * The complete product row is *not* broadcast, because the catalogue is large
 * and the authoritative record always remains the REST API — a client that
 * needs the full row (the Products screen, the POS grid) fetches
 * `GET /products/{id}` and inserts the server's own response. That keeps the
 * event a notification of "this changed", never a second source of truth, and
 * means a field added to `products` later can never silently start leaking
 * through the WebSocket.
 *
 * `ShouldBroadcastNow` (not `ShouldBroadcast`) because this application runs
 * no queue worker: a queued broadcast would be written to the `jobs` table and
 * never delivered. `ShouldDispatchAfterCommit` is the safety half — the
 * broadcast is deferred to the moment the enclosing transaction commits, so a
 * rolled-back product creation never reaches any client.
 */
class ProductCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $businessId  Owning business, taken from the stored product.
     * @param  int  $productId  Id of the product that was created.
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

    /**
     * Event name as the client sees it.
     *
     * A dot-namespaced name rather than the FQCN, so the frontend binds a
     * stable string that survives moving the class between namespaces.
     */
    public function broadcastAs(): string
    {
        return 'product.created';
    }

    /**
     * The wire payload: exactly the minimum needed to locate the change.
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
