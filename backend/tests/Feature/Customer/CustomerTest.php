<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 21 — Customer API contract, filtering and business scoping.
 *
 * Customers are always resolved through the authenticated business, soft
 * deleted when safe, and never expose `business_id` on the wire.
 */
class CustomerTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);

        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherCustomer = Customer::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /**
     * Create a customer inside the authenticated business.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'business_id' => $this->business->id,
            ...$attributes,
        ]);
    }

    /** @test */
    public function customer_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $unauthenticated = [
            ['GET', '/api/v1/customers'],
            ['POST', '/api/v1/customers'],
            ['GET', '/api/v1/customers/1'],
            ['PUT', '/api/v1/customers/1'],
            ['PATCH', '/api/v1/customers/1'],
            ['DELETE', '/api/v1/customers/1'],
        ];

        foreach ($unauthenticated as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'Nope'])
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function authenticated_user_can_list_customers(): void
    {
        $this->customer(['name' => 'Rahim Uddin']);
        // Another tenant's customer must never appear.
        $this->otherCustomer->update(['name' => 'Foreign Customer']);

        $response = $this->getJson('/api/v1/customers');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => ['pagination' => ['current_page' => 1, 'total' => 1, 'last_page' => 1]],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [[
                        'id',
                        'name',
                        'phone',
                        'email',
                        'address',
                        'opening_balance',
                        'status',
                        'created_at',
                        'updated_at',
                    ]],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        $this->assertCount(1, $response->json('data.items'));

        // Internal columns never leak.
        $this->assertArrayNotHasKey('business_id', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.items.0'));
    }

    /** @test */
    public function customer_list_supports_search(): void
    {
        $this->customer([
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'email' => 'rahim@example.com',
        ]);
        // Same first name in another tenant — still isolated.
        Customer::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Rahim Foreign',
            'phone' => '01999999999',
            'email' => 'foreign@example.com',
        ]);

        foreach (['rahim', '0171234', 'rahim@example'] as $term) {
            $response = $this->getJson('/api/v1/customers?search='.$term);

            $response->assertStatus(200);
            $this->assertSame(1, $response->json('data.pagination.total'), "Search [{$term}]");
            $this->assertSame('Rahim Chowdhury', $response->json('data.items.0.name'));
        }
    }

    /** @test */
    public function customer_list_filters_by_status(): void
    {
        $this->customer(['name' => 'Active One', 'status' => Customer::STATUS_ACTIVE]);
        $this->customer(['name' => 'Inactive One', 'status' => Customer::STATUS_INACTIVE]);

        $response = $this->getJson('/api/v1/customers?status=inactive');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Inactive One', $response->json('data.items.0.name'));
    }

    /** @test */
    public function customer_list_is_paginated(): void
    {
        foreach (range(1, 5) as $i) {
            $this->customer(['name' => 'Customer '.$i]);
        }

        $firstPage = $this->getJson('/api/v1/customers?per_page=2');
        $firstPage->assertStatus(200)
            ->assertJson(['data' => ['pagination' => [
                'current_page' => 1,
                'per_page' => 2,
                'total' => 5,
                'last_page' => 3,
            ]]]);
        $this->assertCount(2, $firstPage->json('data.items'));

        $secondPage = $this->getJson('/api/v1/customers?per_page=2&page=2');
        $secondPage->assertStatus(200)
            ->assertJson(['data' => ['pagination' => ['current_page' => 2, 'total' => 5]]]);
        $this->assertCount(2, $secondPage->json('data.items'));
    }

    /** @test */
    public function customer_list_rejects_sort_columns_outside_the_whitelist(): void
    {
        $this->getJson('/api/v1/customers?sort=password')
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['sort']]);
    }

    /** @test */
    public function customer_list_rejects_an_invalid_status_filter(): void
    {
        $this->getJson('/api/v1/customers?status=whatever')
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['status']]);
    }

    /** @test */
    public function authenticated_user_can_create_a_customer(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Rahim Uddin',
            'phone' => '01711111111',
            'email' => 'rahim@example.com',
            'address' => 'Dhaka, Bangladesh',
            'opening_balance' => 250.50,
            'status' => Customer::STATUS_ACTIVE,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => [
                    'name' => 'Rahim Uddin',
                    'phone' => '01711111111',
                    'email' => 'rahim@example.com',
                    'opening_balance' => '250.50',
                    'status' => Customer::STATUS_ACTIVE,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'phone', 'email', 'address', 'opening_balance', 'status', 'created_at'],
            ]);

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
            'name' => 'Rahim Uddin',
        ]);
    }

    /** @test */
    public function creating_a_customer_applies_schema_defaults(): void
    {
        $response = $this->postJson('/api/v1/customers', ['name' => 'Minimal Customer']);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'status' => Customer::STATUS_ACTIVE,
                    'opening_balance' => '0.00',
                    'phone' => null,
                    'email' => null,
                ],
            ]);
    }

    /** @test */
    public function creating_a_customer_without_a_name_returns_the_validation_envelope(): void
    {
        $response = $this->postJson('/api/v1/customers', ['phone' => '01711111111']);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertDatabaseCount('customers', 1); // only the seeded foreign customer
    }

    /** @test */
    public function creating_a_customer_with_an_invalid_email_or_status_returns_422(): void
    {
        $this->postJson('/api/v1/customers', ['name' => 'Bad Email', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['email']]);

        $this->postJson('/api/v1/customers', ['name' => 'Bad Status', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['status']]);
    }

    /** @test */
    public function a_client_supplied_business_id_is_ignored_on_create(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Hijack Attempt',
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
        ]);
    }

    /** @test */
    public function authenticated_user_can_view_their_customer(): void
    {
        $customer = $this->customer(['name' => 'View Me']);

        $response = $this->getJson('/api/v1/customers/'.$customer->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Customer retrieved successfully',
                'data' => ['id' => $customer->id, 'name' => 'View Me'],
            ])
            ->assertJsonStructure(['success', 'message', 'data' => ['id', 'name', 'phone', 'email', 'status']]);

        $this->assertArrayNotHasKey('business_id', $response->json('data'));
    }

    /** @test */
    public function viewing_another_businesss_customer_returns_404(): void
    {
        $this->getJson('/api/v1/customers/'.$this->otherCustomer->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);
    }

    /** @test */
    public function authenticated_user_can_update_their_customer(): void
    {
        $customer = $this->customer(['name' => 'Before Name']);

        $response = $this->putJson('/api/v1/customers/'.$customer->id, [
            'name' => 'After Name',
            'phone' => '01888888888',
            'opening_balance' => 120.75,
            'status' => Customer::STATUS_INACTIVE,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => [
                    'id' => $customer->id,
                    'name' => 'After Name',
                    'opening_balance' => '120.75',
                    'status' => Customer::STATUS_INACTIVE,
                ],
            ]);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'After Name']);
    }

    /** @test */
    public function patch_endpoint_updates_a_customer(): void
    {
        $customer = $this->customer(['name' => 'Patch Before']);

        $this->patchJson('/api/v1/customers/'.$customer->id, ['name' => 'Patch After'])
            ->assertStatus(200)
            ->assertJson(['data' => ['name' => 'Patch After', 'phone' => $customer->phone]]);
    }

    /** @test */
    public function updating_a_customer_with_an_invalid_payload_returns_422(): void
    {
        $customer = $this->customer(['name' => 'Keep Me']);

        $this->putJson('/api/v1/customers/'.$customer->id, ['status' => 'unknown'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['status']]);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Keep Me']);
    }

    /** @test */
    public function updating_a_customer_cannot_change_id_or_business_id(): void
    {
        $customer = $this->customer(['name' => 'Stays Mine']);

        $response = $this->putJson('/api/v1/customers/'.$customer->id, [
            'name' => 'Renamed',
            'id' => 999999,
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'business_id' => $this->business->id,
            'name' => 'Renamed',
        ]);
        $this->assertDatabaseMissing('customers', ['id' => 999999]);
    }

    /** @test */
    public function updating_another_businesss_customer_returns_404(): void
    {
        $this->putJson('/api/v1/customers/'.$this->otherCustomer->id, ['name' => 'Hijacked'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('customers', [
            'id' => $this->otherCustomer->id,
            'name' => $this->otherCustomer->name,
        ]);
    }

    /** @test */
    public function deleting_a_customer_soft_deletes_it(): void
    {
        $customer = $this->customer(['name' => 'Delete Me']);

        $response = $this->deleteJson('/api/v1/customers/'.$customer->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Customer deleted successfully',
            ])
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    /** @test */
    public function a_deleted_customer_disappears_from_the_list(): void
    {
        $customer = $this->customer();

        $this->deleteJson('/api/v1/customers/'.$customer->id)->assertStatus(200);

        $list = $this->getJson('/api/v1/customers');
        $list->assertStatus(200);
        $this->assertCount(0, $list->json('data.items'));
    }

    /** @test */
    public function deleting_a_customer_with_sales_returns_409_and_preserves_history(): void
    {
        $customer = $this->customer(['name' => 'In Use']);
        Sale::create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            // Required since invoice numbering became server-owned: a sale
            // always has a persisted number, so a hand-built fixture must
            // supply one too.
            'invoice_number' => 'INV-CUST0001',
            'sale_date' => now()->toDateString(),
        ]);

        $response = $this->deleteJson('/api/v1/customers/'.$customer->id);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete customer because it is in use.',
                'errors' => [],
            ]);

        // Neither the customer nor the sale is touched.
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
        $this->assertDatabaseCount('sales', 1);
    }

    /** @test */
    public function deleting_another_businesss_customer_returns_404(): void
    {
        $this->deleteJson('/api/v1/customers/'.$this->otherCustomer->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('customers', ['id' => $this->otherCustomer->id, 'deleted_at' => null]);
    }
}
