<?php

declare(strict_types=1);

namespace Tests\Feature\Sale;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\InvoiceSequence;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sale\InvoiceNumberService;
use App\Services\Sale\SaleService;
use App\Support\InvoiceNumber;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Server-authoritative, race-safe sale invoice numbering.
 *
 * The contract under test:
 *
 *  - the backend is the **only** authority — a client-sent `invoice_number`
 *    can never reach the stored value;
 *  - the number is **persisted**, `NOT NULL` and unique per business;
 *  - numbering is a **per-business sequence**, so two businesses both start at
 *    `{prefix}-000001`;
 *  - the sequence advances **once per committed sale**, inside the same
 *    transaction, and a rolled-back sale consumes nothing;
 *  - allocation is a row-locked counter — never `MAX() + 1` or `COUNT() + 1`.
 *
 * Format: `{business_settings.invoice_prefix}-{6-digit zero-padded sequence}`,
 * e.g. `INV-000001`, falling back to `INV` when no usable prefix is set.
 */
class InvoiceNumberingTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        StockMovement::create([
            'business_id' => $this->product->business_id,
            'product_id' => $this->product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '100',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * A valid `POST /sales` body, overridable per test.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]],
        ], $over);
    }

    /** Creates a sale through the API and returns the persisted model. */
    private function checkout(array $over = []): Sale
    {
        $response = $this->postJson('/api/v1/sales', $this->payload($over));
        $response->assertStatus(201);

        return Sale::findOrFail((int) $response->json('data.id'));
    }

    /** Stores an `invoice_prefix` through the existing settings table. */
    private function prefixFor(Business $business, string $value): void
    {
        BusinessSetting::create([
            'business_id' => $business->id,
            'key' => InvoiceNumberService::PREFIX_SETTING,
            'value' => $value,
        ]);
    }

    /**
     * Checks out as a second, independent tenant.
     *
     * `withToken` (rather than `withHeaders`) replaces the bearer token the
     * trait installed for the first user, and `forgetGuards()` drops the auth
     * guard's memoised user so the new identity is actually resolved.
     */
    private function checkoutAs(User $user, Customer $customer, Product $product): TestResponse
    {
        $token = $user->createToken('feature-test')->plainTextToken;

        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500]],
        ]);
    }

    /** @test */
    public function a_new_sale_receives_a_non_null_invoice_number_that_is_persisted(): void
    {
        $response = $this->postJson('/api/v1/sales', $this->payload());

        $response->assertStatus(201);
        $this->assertIsString($response->json('data.invoice_number'));
        $this->assertSame('INV-000001', $response->json('data.invoice_number'));

        // Persisted, not merely echoed back by the resource.
        $sale = Sale::findOrFail((int) $response->json('data.id'));
        $this->assertSame('INV-000001', $sale->invoice_number);
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-000001',
        ]);
    }

    /** @test */
    public function the_sequence_advances_one_per_sale_within_a_business(): void
    {
        $this->assertSame('INV-000001', $this->checkout()->invoice_number);
        $this->assertSame('INV-000002', $this->checkout()->invoice_number);
        $this->assertSame('INV-000003', $this->checkout()->invoice_number);

        $this->assertSame(
            ['INV-000001', 'INV-000002', 'INV-000003'],
            Sale::query()->orderBy('id')->pluck('invoice_number')->all(),
        );
    }

    /** @test */
    public function invoice_numbers_are_unique_within_a_business(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->checkout();
        }

        $numbers = Sale::query()->pluck('invoice_number');

        $this->assertCount(5, $numbers);
        $this->assertCount(5, $numbers->unique(), 'The API must never issue the same number twice.');
    }

    /** @test */
    public function the_database_refuses_a_duplicate_invoice_number_for_a_business(): void
    {
        $existing = $this->checkout();

        // Even with the application code bypassed entirely, the UNIQUE
        // (business_id, invoice_number) index makes a duplicate impossible.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('sales')->insert([
            'business_id' => $existing->business_id,
            'invoice_number' => $existing->invoice_number,
            'sale_date' => now()->toDateString(),
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'grand_total' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'pending',
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function the_column_can_no_longer_hold_a_null_invoice_number(): void
    {
        $sale = $this->checkout();

        $this->expectException(QueryException::class);

        DB::table('sales')->where('id', $sale->id)->update(['invoice_number' => null]);
    }

    /** @test */
    public function each_business_has_an_independent_sequence(): void
    {
        [$otherBusiness, $otherUser, $otherCustomer, $otherProduct] = $this->otherTenant();

        // Business A: 1, 2. Business B: 1, 2. Both restart at 000001.
        $this->assertSame('INV-000001', $this->checkout()->invoice_number);
        $this->assertSame('INV-000002', $this->checkout()->invoice_number);
        $this->assertSame('INV-000001', $this->checkoutAs($otherUser, $otherCustomer, $otherProduct)->assertCreated()->json('data.invoice_number'));
        $this->assertSame('INV-000002', $this->checkoutAs($otherUser, $otherCustomer, $otherProduct)->assertCreated()->json('data.invoice_number'));

        // Exactly one counter row per business — the row that gets locked.
        $this->assertSame(1, InvoiceSequence::query()->forBusiness($this->business->id)->count());
        $this->assertSame(1, InvoiceSequence::query()->forBusiness($otherBusiness->id)->count());
        $this->assertSame(3, $this->app->make(InvoiceNumberService::class)->peek($this->business->id));
        $this->assertSame(3, $this->app->make(InvoiceNumberService::class)->peek($otherBusiness->id));
    }

    /** @test */
    public function a_client_cannot_supply_or_override_the_invoice_number(): void
    {
        // The field is `prohibited`, so a forged number is rejected outright.
        $this->postJson('/api/v1/sales', $this->payload(['invoice_number' => 'FAKE-123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');
        $this->assertDatabaseCount('sales', 0);

        // And even if validation were bypassed, the service never reads the
        // key: the sale is numbered by the server regardless.
        $sale = $this->app->make(SaleService::class)->create(
            $this->business->id,
            $this->user->id,
            $this->payload(['invoice_number' => 'FAKE-123']),
        );

        $this->assertSame('INV-000001', $sale->invoice_number);
        $this->assertDatabaseMissing('sales', ['invoice_number' => 'FAKE-123']);
    }

    /** @test */
    public function an_invoice_number_cannot_be_edited_after_the_sale_is_created(): void
    {
        $sale = $this->checkout();

        $this->patchJson('/api/v1/sales/'.$sale->id, ['invoice_number' => 'RENAMED-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');

        $this->assertSame('INV-000001', $sale->fresh()->invoice_number);
    }

    /** @test */
    public function reloading_a_sale_returns_the_same_invoice_number_everywhere(): void
    {
        $sale = $this->checkout();

        $listed = $this->getJson('/api/v1/sales')->assertOk();
        $shown = $this->getJson('/api/v1/sales/'.$sale->id)->assertOk();

        $this->assertSame($sale->invoice_number, $listed->json('data.items.0.invoice_number'));
        $this->assertSame($sale->invoice_number, $shown->json('data.invoice_number'));
        $this->assertSame($sale->invoice_number, $sale->fresh()->invoice_number);
    }

    /** @test */
    public function the_prefix_comes_from_the_business_settings_of_that_same_business(): void
    {
        $this->prefixFor($this->business, 'ACME');

        $this->assertSame('ACME-000001', $this->checkout()->invoice_number);
        $this->assertSame('ACME-000002', $this->checkout()->invoice_number);

        // A different business keeps the default prefix and its own sequence.
        [$otherBusiness, $otherUser, $otherCustomer, $otherProduct] = $this->otherTenant();
        $this->assertSame(1, $this->app->make(InvoiceNumberService::class)->peek($otherBusiness->id));
        $this->assertSame('INV-000001', $this->checkoutAs($otherUser, $otherCustomer, $otherProduct)->assertCreated()->json('data.invoice_number'));
    }

    /** @test */
    public function a_missing_blank_or_unusable_prefix_falls_back_to_the_documented_default(): void
    {
        $this->assertSame('INV', InvoiceNumber::sanitizePrefix(null));
        $this->assertSame('INV', InvoiceNumber::sanitizePrefix('   '));
        $this->assertSame('INV', InvoiceNumber::sanitizePrefix('---'));
        $this->assertSame('ACME', InvoiceNumber::sanitizePrefix(' a c m e '));

        // And the fallback is what actually reaches the database.
        $this->assertSame('INV-000001', $this->checkout()->invoice_number);
    }

    /** @test */
    public function the_sequence_row_stays_consistent_with_what_was_issued(): void
    {
        $service = $this->app->make(InvoiceNumberService::class);

        $this->assertSame(1, $service->peek($this->business->id));

        $this->checkout();
        $this->assertSame(2, $service->peek($this->business->id));
        $this->assertSame(2, InvoiceSequence::query()->forBusiness($this->business->id)->value('next_number'));

        $this->checkout();
        $this->assertSame(3, $service->peek($this->business->id));

        // The cursor is exactly one past the highest number already issued.
        $issued = Sale::query()->orderBy('id')->pluck('invoice_number')->all();
        $this->assertSame(['INV-000001', 'INV-000002'], $issued);
        $this->assertSame((int) substr((string) end($issued), -6) + 1, $service->peek($this->business->id));
    }

    /** @test */
    public function a_failed_sale_persists_nothing_and_consumes_no_number(): void
    {
        $movementsBefore = StockMovement::query()->count();

        // Insufficient stock aborts the whole transaction; the number must not
        // be left consumed and no partial document may survive.
        $this->postJson('/api/v1/sales', $this->payload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 9999, 'unit_price' => 500]],
        ]))->assertStatus(409);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(1, $this->app->make(InvoiceNumberService::class)->peek($this->business->id));

        // So the next successful sale still gets the first number.
        $this->assertSame('INV-000001', $this->checkout()->invoice_number);
    }

    /** @test */
    public function a_number_issued_to_a_committed_sale_is_never_reissued(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();

        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $this->assertSame('INV-000001', $first->invoice_number);
        $this->assertSame('INV-000002', $second->invoice_number);
    }

    /** @test */
    public function the_backfill_leaves_no_null_and_keeps_numbers_unique_per_business(): void
    {
        // `RefreshDatabase` migrates from scratch, so the backfill migration
        // has already run over the (empty) table. This asserts the end state
        // it is responsible for producing.
        $this->assertSame(0, Sale::query()->whereNull('invoice_number')->count());

        $this->checkout();
        $this->checkout();

        $this->assertSame(0, Sale::query()->whereNull('invoice_number')->count());

        $duplicates = DB::table('sales')
            ->select('business_id', 'invoice_number', DB::raw('count(*) as total'))
            ->groupBy('business_id', 'invoice_number')
            ->having('total', '>', 1)
            ->get();

        $this->assertCount(0, $duplicates, 'No business may hold the same invoice number twice.');
    }

    /** @test */
    public function a_sequence_starts_above_numbers_that_were_already_issued(): void
    {
        // A business whose invoices already carry free-text numbers must never
        // have one of them reissued by the generator.
        $existing = Sale::factory()->create([
            'business_id' => $this->business->id,
            'invoice_number' => 'S-41',
        ]);

        $this->assertSame('S-41', $existing->fresh()->invoice_number);
        $this->assertNotSame('S-41', $this->checkout()->invoice_number);
    }

    /** @test */
    public function existing_sale_behaviour_is_untouched_by_numbering(): void
    {
        $response = $this->postJson('/api/v1/sales', $this->payload([
            'discount' => 100,
            'tax' => 50,
            'paid_amount' => 400,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500]],
        ]));

        $response->assertStatus(201)->assertJson([
            'data' => [
                'invoice_number' => 'INV-000001',
                'subtotal' => '1000.00',
                'grand_total' => '950.00',
                'due_amount' => '550.00',
            ],
        ]);

        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => Sale::class,
            'reference_id' => (int) $response->json('data.id'),
            'type' => StockMovement::TYPE_SALE,
        ]);
    }

    /** Builds a second tenant with one stocked product. */
    private function otherTenant(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        // The second tenant is fully authorized in its own business, so any
        // isolation assertion below is about tenancy rather than permissions.
        $this->grantAllCapabilities($user);
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);
        StockMovement::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '100',
            'created_by' => $user->id,
        ]);

        return [$business, $user, $customer, $product];
    }
}
