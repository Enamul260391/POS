<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Events\Inventory\ProductStockUpdated;
use App\Events\Product\ProductCreated;
use App\Events\Product\ProductDeleted;
use App\Events\Product\ProductUpdated;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place the application announces realtime changes.
 *
 * Every product and stock event in the system is raised from here, which is what
 * keeps the design honest: no controller dispatches an event, and no service
 * outside the product/inventory domain knows broadcasting exists. A new
 * stock-changing flow cannot forget to announce itself, because it does not
 * write to the ledger directly — {@see InventoryService::record()} is the only
 * ledger write path and it announces through this class.
 *
 * Two responsibilities, both required for correctness:
 *
 *  1. **Announce after commit.** `ShouldDispatchAfterCommit` on the event
 *     classes is the mechanism; this class additionally *coalesces* stock
 *     changes so one transaction that touches the same product on several lines
 *     (a sale with two lines of the same item, a multi-line purchase) produces
 *     exactly one event carrying the final balance.
 *  2. **Never break the request.** The events broadcast synchronously
 *     (`ShouldBroadcastNow`) and a broadcast failure — Reverb not running, port
 *     closed, DNS unresolvable — would otherwise surface as an exception from
 *     an HTTP request whose database transaction has *already committed*. That
 *     would turn a working REST call into a 500 and, worse, tempt a client into
 *     retrying a mutation that already succeeded. Every dispatch here is
 *     therefore guarded: the failure is logged and swallowed, and the REST
 *     response is unaffected. Realtime is an enhancement layer, never a
 *     dependency.
 */
final class RealtimeBroadcaster
{
    /**
     * Product ids whose stock has changed inside the current transaction.
     *
     * Keyed `"{businessId}:{productId}"` so two tenants that happen to share a
     * product id cannot coalesce into one another. Instance state rather than
     * static, so a long-lived worker (queue, Octane) never carries a pending
     * product from a previous request.
     *
     * @var array<string, true>
     */
    private array $pendingStockChanges = [];

    /**
     * Announce a newly created product.
     */
    public function productCreated(Product $product): void
    {
        $this->guard(
            fn () => ProductCreated::dispatch((int) $product->business_id, (int) $product->getKey()),
            'product.created',
        );
    }

    /**
     * Announce a change to a product's master data.
     */
    public function productUpdated(Product $product): void
    {
        $this->guard(
            fn () => ProductUpdated::dispatch((int) $product->business_id, (int) $product->getKey()),
            'product.updated',
        );
    }

    /**
     * Announce that a product left the catalogue.
     */
    public function productDeleted(Product $product): void
    {
        $this->guard(
            fn () => ProductDeleted::dispatch((int) $product->business_id, (int) $product->getKey()),
            'product.deleted',
        );
    }

    /**
     * Announce that a product's on-hand quantity changed.
     *
     * The balance is deliberately **not** computed here. This method only notes
     * that the product is dirty and registers a single after-commit callback;
     * when the transaction commits, the callback re-reads the ledger through
     * {@see InventoryService::currentStock()} and broadcasts that authoritative
     * value.
     *
     * Reading the balance at commit time rather than at call time matters for
     * correctness, not just tidiness: a transaction that writes several
     * movements for one product (a sale with two lines of the same item) has no
     * correct intermediate balance at all. Only the committed ledger has one,
     * and that is what the client must be told.
     */
    public function stockChanged(Product $product): void
    {
        $businessId = (int) $product->business_id;
        $productId = (int) $product->getKey();
        $key = $businessId.':'.$productId;

        // Already scheduled in this transaction: one event for the product is
        // enough, and it will be computed from the final committed balance.
        if (isset($this->pendingStockChanges[$key])) {
            return;
        }

        $this->pendingStockChanges[$key] = true;

        // Cleared on commit *and* on rollback, so a failed transaction cannot
        // leave a product permanently muted for the rest of the request.
        DB::afterRollBack(fn () => $this->forgetStockChange($key));

        DB::afterCommit(function () use ($key, $businessId, $productId): void {
            $this->forgetStockChange($key);

            // Resolved from the container rather than injected: the broadcaster
            // is a dependency of InventoryService, so constructor injection
            // would be circular. By the time this callback runs the object graph
            // is already built, so the lazy resolve is safe — and it keeps
            // InventoryService as the only implementation of the balance.
            $stock = app(InventoryService::class)->currentStock($businessId, $productId);

            $this->guard(
                fn () => ProductStockUpdated::dispatch($businessId, $productId, $stock),
                'product.stock.updated',
            );
        });
    }

    /**
     * Dispatch a broadcast event without ever letting it fail the request.
     *
     * @param  callable(): void  $dispatch
     * @param  string  $event  Named in the log line so a failure is diagnosable.
     */
    private function guard(callable $dispatch, string $event): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            // The database work is already committed at this point, so there is
            // nothing to roll back and nothing the client should be told: a
            // missed realtime frame is repaired by its next `GET`, which the
            // frontend is built to do anyway. Logged as a warning so a stopped
            // Reverb server is visible in `storage/logs` rather than silent.
            Log::warning('Realtime broadcast failed; the REST response is unaffected.', [
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drop a product from the coalescing set.
     */
    private function forgetStockChange(string $key): void
    {
        unset($this->pendingStockChanges[$key]);
    }
}

