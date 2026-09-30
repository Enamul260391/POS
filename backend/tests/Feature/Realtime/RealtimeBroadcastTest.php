<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Events\Inventory\ProductStockUpdated;
use App\Events\Product\ProductCreated;
use App\Events\Product\ProductDeleted;
use App\Events\Product\ProductUpdated;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase RT-1 — realtime product and stock broadcasting.
 *
 * Four properties are under test, and they are the ones that actually matter:
 *
 *  1. the right event is raised for each product change;
 *  2. a payload carries only the minimum identifying fields;
 *  3. the event appears **only after the transaction commits** — a rolled-back
 *     sale, purchase or return must leave no trace on any client;
 *  4. the business private channel refuses cross-tenant subscription.
 *
 * `Event::fake()` records dispatches instead of performing them, and — as
 * `EventFake::fakeEvent()` shows — it honours `ShouldDispatchAfterCommit` by
 * deferring the record through `db.transactions`. That is what lets the
 * after-commit behaviour itself be asserted rather than assumed.
 *
 * Stock expectations are written as `'25'`, not `'25.0000'`: balances go
 * through `Quantity::format()`, which emits a decimal string with at most four
 * fractional digits and **no trailing zeros** (`'5.25'`, `'10'`). That is the
 * same formatting the REST API already returns, so a client sees one
 * representation whether it read the value over HTTP or the socket.
 */
class RealtimeBroadcastTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->otherBusiness = Business::factory()->create();
    }

    // ---------------------------------------------------------------------
    // Event shape
    // ---------------------------------------------------------------------

    /** @test */
    public function product_events_broadcast_after_commit_and_synchronously(): void
    {
        // The deployment runs no queue worker, so a `ShouldBroadcast` event
        // would be written to the `jobs` table and never delivered. Every event
        // must therefore be a synchronous broadcast, and — because the row and
        // the balance must already be committed when clients are told about
        // them — must also be deferred to the commit.
        $events = [
            ProductCreated::class,
            ProductUpdated::class,
            ProductDeleted::class,
            ProductStockUpdated::class,
        ];

        foreach ($events as $event) {
            $this->assertContains(
                ShouldBroadcastNow::class,
                class_implements($event),
                "{$event} must broadcast synchronously (no queue worker runs).",
            );

            $this->assertContains(
                ShouldDispatchAfterCommit::class,
                class_implements($event),
                "{$event} must only be dispatched once the transaction commits.",
            );
        }
    }

    /** @test */
    public function product_event_payloads_carry_only_minimum_identifying_fields(): void
    {
        $productId = 4242;

        $this->assertSame(
            ['business_id' => 7, 'product_id' => $productId],
            (new ProductCreated(7, $productId))->broadcastWith(),
        );

        $this->assertSame(
            ['business_id' => 7, 'product_id' => $productId],
            (new ProductUpdated(7, $productId))->broadcastWith(),
        );

        $this->assertSame(
            ['business_id' => 7, 'product_id' => $productId],
            (new ProductDeleted(7, $productId))->broadcastWith(),
        );
    }

    /** @test */
    public function stock_event_payload_carries_the_authoritative_balance(): void
    {
        $payload = (new ProductStockUpdated(7, 4242, '12.5'))->broadcastWith();

        $this->assertSame(
            ['business_id' => 7, 'product_id' => 4242, 'current_stock' => '12.5'],
            $payload,
        );

        // A string, never a float: the ledger is fixed-point and a JSON number
        // would reintroduce exactly the drift `Quantity` exists to prevent.
        $this->assertIsString($payload['current_stock']);
    }

    /** @test */
    public function every_event_is_sent_to_a_private_business_channel(): void
    {
        $events = [
            new ProductCreated(7, 1),
            new ProductUpdated(7, 1),
            new ProductDeleted(7, 1),
            new ProductStockUpdated(7, 1, '1.0000'),
        ];

        foreach ($events as $event) {
            $channels = $event->broadcastOn();

            $this->assertCount(1, $channels);
            // The `private-` prefix is what forces Reverb to demand a signed
            // auth response; a public channel would leak tenant data to any
            // socket that guessed the name.
            $this->assertStringStartsWith('private-business.', $channels[0]->name);
        }
    }

    // ---------------------------------------------------------------------
    // Product lifecycle
    // ---------------------------------------------------------------------

    /** @test */
    public function creating_a_product_announces_product_created(): void
    {
        Event::fake();

        $response = $this->postJson('/api/v1/products', ['name' => 'Realtime Widget']);

        $response->assertCreated();

        $productId = $response->json('data.id');

        Event::assertDispatched(
            ProductCreated::class,
            fn (ProductCreated $event): bool => $event->productId === $productId
                && $event->businessId === $this->business->id,
        );
    }

    /** @test */
    public function updating_a_product_announces_product_updated(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        Event::fake();

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed Widget'])
            ->assertOk();

        Event::assertDispatched(
            ProductUpdated::class,
            fn (ProductUpdated $event): bool => $event->productId === $product->id
                && $event->businessId === $this->business->id,
        );

        Event::assertNotDispatched(ProductCreated::class);
    }

    /** @test */
    public function deleting_a_product_announces_product_deleted(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        Event::fake();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        Event::assertDispatched(
            ProductDeleted::class,
            fn (ProductDeleted $event): bool => $event->productId === $product->id,
        );
    }

    // ---------------------------------------------------------------------
    // Stock: every flow funnels through the one ledger write path
    // ---------------------------------------------------------------------

    /** @test */
    public function a_manual_adjustment_announces_the_authoritative_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        Event::fake();

        $this->postJson("/api/v1/products/{$product->id}/stock-adjustments", [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 25,
        ])->assertOk();

        Event::assertDispatched(
            ProductStockUpdated::class,
            fn (ProductStockUpdated $event): bool => $event->productId === $product->id
                && $event->businessId === $this->business->id
                // The balance comes from the committed ledger, never from the
                // request: the client is told what actually persisted.
                && $event->currentStock === '25',
        );
    }

    /** @test */
    public function a_purchase_stock_in_announces_the_authoritative_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);

        Event::fake();

        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-01-05',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 4.00,
                'total' => 40.00,
            ]],
        ])->assertCreated();

        Event::assertDispatched(
            ProductStockUpdated::class,
            fn (ProductStockUpdated $event): bool => $event->productId === $product->id
                && $event->currentStock === '10',
        );
    }

    /** @test */
    public function a_sale_announces_the_decreased_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->seedStock($product, 50);

        Event::fake();

        $this->postJson('/api/v1/sales', [
            'sale_date' => '2026-01-05',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 9.99,
                'total' => 29.97,
            ]],
        ])->assertCreated();

        Event::assertDispatched(
            ProductStockUpdated::class,
            fn (ProductStockUpdated $event): bool => $event->productId === $product->id
                && $event->currentStock === '47',
        );
    }

    /** @test */
    public function several_lines_of_one_product_announce_a_single_final_balance(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->seedStock($product, 100);

        Event::fake();

        // The same product on two lines: the intermediate balances (97, then 95)
        // are not states the product was ever in, so announcing them would be
        // wrong, not merely noisy.
        $this->postJson('/api/v1/sales', [
            'sale_date' => '2026-01-05',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 9.99,
                    'total' => 29.97,
                ],
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 9.99,
                    'total' => 19.98,
                ],
            ],
        ])->assertCreated();

        $dispatched = Event::dispatched(ProductStockUpdated::class)
            ->filter(fn (array $args): bool => $args[0]->productId === $product->id)
            ->values();

        $this->assertCount(1, $dispatched, 'One product changed once, so one event.');
        $this->assertSame('95', $dispatched[0][0]->currentStock);
    }

    // ---------------------------------------------------------------------
    // Transaction safety — the property that matters most
    // ---------------------------------------------------------------------

    /** @test */
    public function a_rolled_back_transaction_announces_nothing(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->seedStock($product, 10);

        Event::fake();

        try {
            // A sale for more than is on hand: the business rule throws, the
            // transaction rolls back, and nothing may reach a client.
            $this->postJson('/api/v1/sales', [
                'sale_date' => '2026-01-05',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 999,
                    'unit_price' => 9.99,
                    'total' => 9989.01,
                ]],
            ])->assertStatus(409);
        } finally {
            Event::assertNotDispatched(ProductStockUpdated::class);
        }

        $this->assertSame(
            '10',
            app(InventoryService::class)->currentStock($this->business->id, $product->id),
        );
    }

    /** @test */
    public function nothing_is_announced_before_the_transaction_commits(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $inventory = app(InventoryService::class);

        Event::fake();
        $fake = Event::getFacadeRoot();
        $this->assertInstanceOf(EventFake::class, $fake);

        DB::beginTransaction();
        $inventory->record($product, $this->user->id, StockMovement::TYPE_RECEIVE, '5');

        // Still inside the transaction: the ledger already holds the row, but
        // no client may be told about it yet, because a rollback is still
        // possible. This is the exact ordering the phase requires.
        $this->assertCount(0, $fake->dispatched(ProductStockUpdated::class));

        DB::commit();

        $this->assertCount(1, $fake->dispatched(ProductStockUpdated::class));
    }

    /** @test */
    public function a_dead_reverb_server_does_not_fail_the_rest_request(): void
    {
        // Point the broadcaster at a port nothing listens on: exactly the
        // "developer forgot to run reverb:start" case.
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.port', 65534);

        $product = Product::factory()->create(['business_id' => $this->business->id]);

        // No Event::fake() here — the real broadcaster must run, and the HTTP
        // response must still succeed.
        $this->postJson("/api/v1/products/{$product->id}/stock-adjustments", [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 3,
        ])->assertOk();

        // The write is committed regardless of the realtime layer.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    // ---------------------------------------------------------------------
    // Business isolation
    // ---------------------------------------------------------------------

    /** @test */
    public function a_user_may_only_authorize_their_own_business_channel(): void
    {
        // Their own channel: allowed.
        $this->assertTrue($this->authorizes($this->user, $this->business->id));

        // Another tenant's channel: refused. This is the security boundary —
        // frontend filtering is irrelevant if Reverb would have accepted it.
        $this->assertFalse($this->authorizes($this->user, $this->otherBusiness->id));
    }

    /** @test */
    public function stock_events_never_carry_another_businesss_channel(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        Event::fake();

        $this->postJson("/api/v1/products/{$product->id}/stock-adjustments", [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 1,
        ])->assertOk();

        $event = Event::dispatched(ProductStockUpdated::class)->first()[0];

        $this->assertSame($this->business->id, $event->businessId);
        $this->assertSame('private-business.'.$this->business->id, $event->broadcastOn()[0]->name);
    }

    /** @test */
    public function the_broadcasting_auth_endpoint_requires_authentication(): void
    {
        $this->useRealBroadcaster();

        $this->flushHeaders();

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.'.$this->business->id,
        ])->assertStatus(401);
    }

    /** @test */
    public function the_broadcasting_auth_endpoint_refuses_another_businesss_channel(): void
    {
        $this->useRealBroadcaster();

        // Reverb treats a 403 from this endpoint as "subscription refused", so
        // this is the assertion that actually keeps Business B's data private.
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.'.$this->otherBusiness->id,
        ])->assertForbidden();
    }

    /** @test */
    public function the_broadcasting_auth_endpoint_authorizes_the_own_business_channel(): void
    {
        $this->useRealBroadcaster();

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.'.$this->business->id,
        ])->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Run a channel's authorization callback as the given user.
     *
     * Invoked directly rather than over HTTP because the callback — not the
     * endpoint — is the security boundary being asserted here. The registered
     * value is the raw closure from routes/channels.php, so this is exactly
     * what Reverb's own `verifyUserCanAccessChannel()` would evaluate.
     */
    private function authorizes(User $user, int $businessId): bool
    {
        $callback = $this->app->make(BroadcastingFactory::class)
            ->connection()
            ->getChannels()
            ->get('business.{businessId}');

        $this->assertIsCallable($callback, 'The business channel must be registered.');

        return (bool) $callback($user, $businessId);
    }

    /**
     * Switch from the `null` broadcaster to Reverb's real one for this test.
     *
     * Two details make this necessary, and both are easy to get wrong:
     *
     *  - phpunit.xml sets `BROADCAST_CONNECTION=null`, and both
     *    `NullBroadcaster` and `LogBroadcaster` implement `auth()` as a
     *    deliberate **no-op**. Under either, the channel callback never runs and
     *    an authorization assertion would pass vacuously.
     *  - `routes/channels.php` registers the callback on whichever broadcaster
     *    is resolved when the file is read, i.e. at boot. Changing the default
     *    connection afterwards hands out a *new* broadcaster that has no channel
     *    registered, so the callback is re-required here against the connection
     *    actually in use.
     *
     * The `reverb` driver resolves to `PusherBroadcaster`, whose `auth()` really
     * does call `verifyUserCanAccessChannel()` and therefore really does evaluate
     * routes/channels.php. Signing the response is a local HMAC, so this needs
     * no running Reverb server — the assertions exercise the same code path
     * production uses.
     */
    private function useRealBroadcaster(): void
    {
        config()->set('broadcasting.default', 'reverb');

        require base_path('routes/channels.php');
    }

    /** Give a product an opening balance through the public API. */
    private function seedStock(Product $product, int $quantity): void
    {
        $this->postJson("/api/v1/products/{$product->id}/stock-adjustments", [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => $quantity,
        ])->assertOk();
    }
}
