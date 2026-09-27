<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\Business;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 21 — Supplier API contract, filtering and business scoping.
 *
 * Suppliers are always resolved through the authenticated business, soft
 * deleted when no purchase references them, and never expose `business_id`.
 */
class SupplierTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);

        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherSupplier = Supplier::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /**
     * Create a supplier inside the authenticated business.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::factory()->create([
            'business_id' => $this->business->id,
            ...$attributes,
        ]);
    }

    /** @test */
    public function supplier_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $unauthenticated = [
            ['GET', '/api/v1/suppliers'],
            ['POST', '/api/v1/suppliers'],
            ['GET', '/api/v1/suppliers/1'],
            ['PUT', '/api/v1/suppliers/1'],
            ['PATCH', '/api/v1/suppliers/1'],
            ['DELETE', '/api/v1/suppliers/1'],
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
    public function authenticated_user_can_list_suppliers(): void
    {
        $this->supplier(['name' => 'Acme Traders']);
        $this->otherSupplier->update(['name' => 'Foreign Supplier']);

        $response = $this->getJson('/api/v1/suppliers');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Suppliers retrieved successfully',
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
        $this->assertArrayNotHasKey('business_id', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.items.0'));
    }

    /** @test */
    public function supplier_list_supports_search(): void
    {
        $this->supplier([
            'name' => 'Karim Suppliers',
            'phone' => '01612345678',
            'email' => 'karim@suppliers.test',
        ]);
        // Same first name in another tenant — still isolated.
        Supplier::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Karim Foreign',
        ]);

        foreach (['karim', '0161234', 'karim@suppliers'] as $term) {
            $response = $this->getJson('/api/v1/suppliers?search='.$term);

            $response->assertStatus(200);
            $this->assertSame(1, $response->json('data.pagination.total'), "Search [{$term}]");
            $this->assertSame('Karim Suppliers', $response->json('data.items.0.name'));
        }
    }

    /** @test */
    public function supplier_list_filters_by_status(): void
    {
        $this->supplier(['name' => 'Active Supplier', 'status' => Supplier::STATUS_ACTIVE]);
        $this->supplier(['name' => 'Inactive Supplier', 'status' => Supplier::STATUS_INACTIVE]);

        $response = $this->getJson('/api/v1/suppliers?status=inactive');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Inactive Supplier', $response->json('data.items.0.name'));
    }

    /** @test */
    public function supplier_list_is_paginated(): void
    {
        foreach (range(1, 5) as $i) {
            $this->supplier(['name' => 'Supplier '.$i]);
        }

        $firstPage = $this->getJson('/api/v1/suppliers?per_page=2');
        $firstPage->assertStatus(200)
            ->assertJson(['data' => ['pagination' => [
                'current_page' => 1,
                'per_page' => 2,
                'total' => 5,
                'last_page' => 3,
            ]]]);
        $this->assertCount(2, $firstPage->json('data.items'));

        $secondPage = $this->getJson('/api/v1/suppliers?per_page=2&page=2');
        $secondPage->assertStatus(200)
            ->assertJson(['data' => ['pagination' => ['current_page' => 2, 'total' => 5]]]);
        $this->assertCount(2, $secondPage->json('data.items'));
    }

    /** @test */
    public function supplier_list_rejects_sort_columns_outside_the_whitelist(): void
    {
        $this->getJson('/api/v1/suppliers?sort=secret')
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['sort']]);
    }

    /** @test */
    public function authenticated_user_can_create_a_supplier(): void
    {
        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Acme Traders',
            'phone' => '01722222222',
            'email' => 'sales@acme.test',
            'address' => 'Chattogram',
            'opening_balance' => 1000.25,
            'status' => Supplier::STATUS_ACTIVE,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Supplier created successfully',
                'data' => [
                    'name' => 'Acme Traders',
                    'phone' => '01722222222',
                    'opening_balance' => '1000.25',
                    'status' => Supplier::STATUS_ACTIVE,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'phone', 'email', 'address', 'opening_balance', 'status', 'created_at'],
            ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
            'name' => 'Acme Traders',
        ]);
    }

    /** @test */
    public function creating_a_supplier_without_a_name_returns_the_validation_envelope(): void
    {
        $this->postJson('/api/v1/suppliers', ['phone' => '01722222222'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertDatabaseCount('suppliers', 1); // only the seeded foreign supplier
    }

    /** @test */
    public function a_client_supplied_business_id_is_ignored_on_supplier_create(): void
    {
        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Hijack Supplies',
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('suppliers', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
        ]);
    }

    /** @test */
    public function supplier_list_rejects_bad_status(): void
    {
        $this->getJson('/api/v1/suppliers?status=whatever')
            ->assertStatus(422);
    }

    /** @test */
    public function creating_supplier_applies_defaults(): void
    {
        $r = $this->postJson('/api/v1/suppliers', ['name' => 'Minimal']);
        $r->assertStatus(201)->assertJson(['data' => ['status' => 'active']]);
    }

    /** @test */
    public function can_view_own_supplier(): void
    {
        $s = $this->supplier(['name' => 'View Me']);
        $r = $this->getJson('/api/v1/suppliers/'.$s->id);
        $r->assertStatus(200)->assertJson(['data' => ['id' => $s->id]]);
        $this->assertArrayNotHasKey('business_id', $r->json('data'));
    }

    /** @test */
    public function view_other_supplier_404(): void
    {
        $this->getJson('/api/v1/suppliers/'.$this->otherSupplier->id)->assertStatus(404);
    }

    /** @test */
    public function can_update_own_supplier(): void
    {
        $s = $this->supplier(['name' => 'Before']);
        $r = $this->putJson('/api/v1/suppliers/'.$s->id, ['name' => 'After', 'status' => 'inactive']);
        $r->assertStatus(200)->assertJson(['data' => ['name' => 'After']]);
        $this->assertDatabaseHas('suppliers', ['id' => $s->id, 'name' => 'After']);
    }

    /** @test */
    public function patch_supplier(): void
    {
        $s = $this->supplier(['name' => 'PB']);
        $this->patchJson('/api/v1/suppliers/'.$s->id, ['name' => 'PA'])
            ->assertStatus(200)->assertJson(['data' => ['name' => 'PA']]);
    }

    /** @test */
    public function update_other_supplier_404(): void
    {
        $this->putJson('/api/v1/suppliers/'.$this->otherSupplier->id, ['name' => 'X'])->assertStatus(404);
    }

    /** @test */
    public function delete_supplier_soft_deletes(): void
    {
        $s = $this->supplier();
        $this->deleteJson('/api/v1/suppliers/'.$s->id)->assertStatus(200);
        $this->assertSoftDeleted('suppliers', ['id' => $s->id]);
    }

    /** @test */
    public function delete_supplier_in_use_409(): void
    {
        $s = $this->supplier();
        Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $s->id]);
        $this->deleteJson('/api/v1/suppliers/'.$s->id)->assertStatus(409);
        $this->assertDatabaseHas('suppliers', ['id' => $s->id, 'deleted_at' => null]);
    }

    /** @test */
    public function delete_other_supplier_404(): void
    {
        $this->deleteJson('/api/v1/suppliers/'.$this->otherSupplier->id)->assertStatus(404);
    }
}

