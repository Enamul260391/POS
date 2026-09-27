<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 20 — inventory ledger: current stock calculation and manual
 * stock adjustments.
 *
 * `stock_movements` is the single source of truth: it stores a signed
 * quantity, so current stock is `SUM(quantity)` per product. There is no
 * mutable stock column anywhere.
 */
class InventoryTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Product $product;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherProduct = Product::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /**
     * Post a stock adjustment as the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    private function adjust(Product $product, array $payload): TestResponse
    {
        return $this->postJson(
            '/api/v1/products/'.$product->id.'/stock-adjustments',
            $payload,
        );
    }

    /** @test */
    public function inventory_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/v1/products/1/stock-adjustments', [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 5,
        ])
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);

        $this->getJson('/api/v1/stock-movements')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);
    }

    /** @test */
    public function receive_adjustment_creates_a_movement_and_updates_the_calculated_stock(): void
    {
        $response = $this->adjust($this->product, [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 5,
            'note' => 'Initial stock',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => [
                    'stock_before' => '0',
                    'current_stock' => '5',
                    'movement' => [
                        'type' => StockMovement::TYPE_RECEIVE,
                        'quantity' => '5',
                        'note' => 'Initial stock',
                        'product_id' => $this->product->id,
                        'created_by' => $this->user->id,
                    ],
                    'product' => [
                        'id' => $this->product->id,
                        'current_stock' => '5',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['product', 'movement', 'stock_before', 'current_stock'],
            ]);

        // The movement carries the correct tenancy and audit trail.
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function remove_adjustment_reduces_the_calculated_stock(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 10])
            ->assertStatus(200);

        $response = $this->adjust($this->product, [
            'type' => StockMovement::TYPE_REMOVE,
            'quantity' => 4,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'stock_before' => '10',
                    'current_stock' => '6',
                    'movement' => [
                        'type' => StockMovement::TYPE_REMOVE,
                        // The ledger stores the signed change, not the amount
                        // the user typed.
                        'quantity' => '-4',
                    ],
                ],
            ]);

        $this->assertDatabaseCount('stock_movements', 2);
    }

    /** @test */
    public function set_adjustment_overrides_the_calculated_balance_with_a_recount(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 10])
            ->assertStatus(200);

        $response = $this->adjust($this->product, [
            'type' => StockMovement::TYPE_SET,
            'quantity' => 3,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'stock_before' => '10',
                    'current_stock' => '3',
                    'movement' => [
                        'type' => StockMovement::TYPE_SET,
                        // Recount stores the delta between counted and
                        // calculated: 3 - 10 = -7.
                        'quantity' => '-7',
                    ],
                ],
            ]);
    }

    /** @test */
    public function setting_stock_to_zero_is_allowed(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 5])
            ->assertStatus(200);

        $this->adjust($this->product, ['type' => StockMovement::TYPE_SET, 'quantity' => 0])
            ->assertStatus(200)
            ->assertJson(['data' => ['current_stock' => '0']]);
    }

    /** @test */
    public function removing_more_stock_than_on_hand_is_rejected_without_writing(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 2])
            ->assertStatus(200);

        $response = $this->adjust($this->product, [
            'type' => StockMovement::TYPE_REMOVE,
            'quantity' => 5,
        ]);

        // Controlled business error — not a clamp to zero, not a crash.
        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Only 2 on hand — cannot remove 5.',
                'errors' => [],
            ]);

        // The rejected adjustment wrote nothing: the failed operation left
        // the ledger exactly as it was (DB transaction rolled back / never
        // inserted), and the balance is unchanged.
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('2', $this->currentStock($this->product));
    }

    /** @test */
    public function a_negative_recount_quantity_is_rejected(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 3])
            ->assertStatus(200);

        // A recount is a physical count: it can never be negative, so the
        // ledger can never be pushed below zero through `set`.
        $this->adjust($this->product, ['type' => StockMovement::TYPE_SET, 'quantity' => -5])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['quantity']]);

        // Still 3 on hand — nothing was clamped or partially written.
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('3', $this->currentStock($this->product));
    }

    /** @test */
    public function a_no_op_recount_is_rejected(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 10])
            ->assertStatus(200);

        $this->adjust($this->product, ['type' => StockMovement::TYPE_SET, 'quantity' => 10])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Stock is already 10.',
            ]);

        // No meaningless ledger row was added.
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /** @test */
    public function invalid_adjustment_payloads_return_the_validation_envelope(): void
    {
        $cases = [
            // Negative quantity is never valid.
            [['type' => StockMovement::TYPE_RECEIVE, 'quantity' => -1], 'quantity'],
            // Non-numeric quantity.
            [['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 'abc'], 'quantity'],
            // Zero is meaningless for receive/remove (gt:0).
            [['type' => StockMovement::TYPE_REMOVE, 'quantity' => 0], 'quantity'],
            // Unknown movement type.
            [['type' => 'in', 'quantity' => 5], 'type'],
            // Missing type.
            [['quantity' => 5], 'type'],
            // Missing quantity.
            [['type' => StockMovement::TYPE_RECEIVE], 'quantity'],
        ];

        foreach ($cases as [$payload, $field]) {
            $this->adjust($this->product, $payload)
                ->assertStatus(422)
                ->assertJson(['success' => false, 'message' => 'Validation failed'])
                ->assertJsonStructure(['success', 'message', 'errors' => [$field]]);
        }

        // No movement was created by any of the rejected payloads.
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @test */
    public function adjusting_another_businesss_product_returns_404(): void
    {
        $response = $this->adjust($this->otherProduct, [
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => 5,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found',
                'errors' => [],
            ]);

        // Nothing was written into either tenant's ledger.
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @test */
    public function service_products_cannot_be_adjusted(): void
    {
        $service = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => Product::UNIT_SERVICE,
        ]);

        $this->adjust($service, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 5])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => $service->name.' does not track stock.',
                'errors' => [],
            ]);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @test */
    public function current_stock_is_the_sum_of_all_ledger_movements(): void
    {
        // Fractional quantities exercise the fixed-point (decimal 15,4)
        // arithmetic: 7.5 + 2.5 - 1 = 9, exactly.
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 7.5])
            ->assertStatus(200);
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 2.5])
            ->assertStatus(200);
        $this->adjust($this->product, ['type' => StockMovement::TYPE_REMOVE, 'quantity' => 1])
            ->assertStatus(200);

        $this->assertSame('9', $this->currentStock($this->product));

        // The product endpoint reports the same ledger-derived number.
        $this->getJson('/api/v1/products/'.$this->product->id)
            ->assertStatus(200)
            ->assertJson(['data' => ['current_stock' => '9']]);
    }

    /** @test */
    public function stock_movement_list_is_scoped_to_the_business(): void
    {
        // Movements in both tenants' ledgers.
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 5])
            ->assertStatus(200);
        StockMovement::create([
            'business_id' => $this->otherBusiness->id,
            'product_id' => $this->otherProduct->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '99',
            'created_by' => $this->otherUser->id,
        ]);

        $response = $this->getJson('/api/v1/stock-movements');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Stock movements retrieved successfully',
                'data' => ['pagination' => ['total' => 1]],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [['id', 'product_id', 'type', 'quantity', 'created_by', 'created_at']],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($this->product->id, $response->json('data.items.0.product_id'));

        // business_id never leaks.
        $this->assertArrayNotHasKey('business_id', $response->json('data.items.0'));
    }

    /** @test */
    public function stock_movement_list_filters_by_product_and_type(): void
    {
        $this->adjust($this->product, ['type' => StockMovement::TYPE_RECEIVE, 'quantity' => 5])
            ->assertStatus(200);
        $this->adjust($this->product, ['type' => StockMovement::TYPE_REMOVE, 'quantity' => 2])
            ->assertStatus(200);

        $byType = $this->getJson('/api/v1/stock-movements?type=remove');
        $byType->assertStatus(200);
        $this->assertCount(1, $byType->json('data.items'));
        $this->assertSame('remove', $byType->json('data.items.0.type'));

        $byProduct = $this->getJson('/api/v1/stock-movements?product_id='.$this->product->id);
        $byProduct->assertStatus(200);
        $this->assertCount(2, $byProduct->json('data.items'));

        // A product id from another business matches nothing (no existence
        // leak) instead of erroring or returning foreign rows.
        $foreign = $this->getJson('/api/v1/stock-movements?product_id='.$this->otherProduct->id);
        $foreign->assertStatus(200);
        $this->assertCount(0, $foreign->json('data.items'));
    }

    /**
     * Ledger balance of a product, read straight from the database aggregate
     * (the same calculation the API uses).
     */
    private function currentStock(Product $product): string
    {
        $sum = StockMovement::query()
            ->forBusiness($product->business_id)
            ->forProduct($product->id)
            ->sum('quantity');

        return Quantity::format($sum);
    }
}
