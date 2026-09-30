<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use App\Support\BusinessChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A product's on-hand quantity changed.
 *
 * This is the one realtime event that carries a *value* rather than a pointer,
 * because a stock figure has to be exact: the POS grid shows it, and a client
 * that had to round-trip `GET /products/{id}` for every movement would fall
 * behind during a busy shift. The value is therefore the authoritative ledger
 * balance, read from `stock_movements` by the backend *after* the transaction
 * that produced it committed.
 *
 * Two properties this event must always hold:
 *
 *  1. **Authoritative.** `currentStock` is a decimal string produced by
 *     {@see \App\Support\Quantity} from `SUM(stock_movements.quantity)`. The
 *     client is told the balance; it never derives one (`stock - quantity`).
 *  2. **After commit.** The event implements `ShouldDispatchAfterCommit`, so a
 *     rolled-back sale, purchase or return never reaches a client — the
 *     broadcast is attached to the commit, not to the write.
 *
 * `ShouldBroadcastNow` rather than `ShouldBroadcast`: this deployment runs no
 * queue worker, so a queued broadcast would sit unprocessed in the `jobs` table
 * forever.
 */
class ProductStockUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int     $businessId   Owning business, taken from the stored product.
     * @param  int     $productId    Product whose balance changed.
     * @param  string  $currentStock Authoritative post-commit balance, decimal string.
     */
    public function __construct(
        public readonly int $businessId,
        public readonly int $productId,
        public readonly string $currentStock,
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
        return 'product.stock.updated';
    }

    /**
     * The wire payload.
     *
     * `current_stock` stays a string: the ledger is fixed-point with four
     * decimal places, and JSON numbers would reintroduce float drift on the
     * client — exactly what the `Quantity` support class exists to prevent.
     *
     * @return array{business_id: int, product_id: int, current_stock: string}
     */
    public function broadcastWith(): array
    {
        return [
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'current_stock' => $this->currentStock,
        ];
    }
}
