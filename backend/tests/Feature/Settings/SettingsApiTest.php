<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 24B — business settings API, isolation and secret handling.
 */
class SettingsApiTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private Business $otherBusiness;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();

        $this->user = $this->userWithPermissions(
            [PermissionService::SETTINGS_VIEW, PermissionService::SETTINGS_UPDATE],
            $this->business->id,
        );
    }

    /** @test */
    public function the_settings_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        foreach ([['GET', '/api/v1/settings'], ['PUT', '/api/v1/settings'], ['PATCH', '/api/v1/settings']] as [$method, $uri]) {
            $this->json($method, $uri, ['receipt_footer' => 'Thanks'])
                ->assertStatus(401)
                ->assertJson(['success' => false, 'message' => 'Unauthenticated', 'errors' => []]);
        }
    }

    /** @test */
    public function reading_settings_requires_the_view_permission(): void
    {
        $this->userWithoutPermissions($this->business->id);

        $this->getJson('/api/v1/settings')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
    }

    /** @test */
    public function updating_settings_requires_the_update_permission(): void
    {
        $this->userWithPermissions([PermissionService::SETTINGS_VIEW], $this->business->id);

        $this->putJson('/api/v1/settings', ['receipt_footer' => 'Thanks'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
    }

    /** @test */
    public function a_business_sees_only_its_own_settings(): void
    {
        BusinessSetting::create(['business_id' => $this->business->id, 'key' => 'receipt_footer', 'value' => 'Ours']);
        BusinessSetting::create(['business_id' => $this->otherBusiness->id, 'key' => 'receipt_footer', 'value' => 'Theirs']);

        $response = $this->getJson('/api/v1/settings');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Settings retrieved successfully'])
            ->assertJsonPath('data.0.key', 'receipt_footer')
            ->assertJsonPath('data.0.value', 'Ours');

        $this->assertStringNotContainsString('Theirs', (string) $response->getContent());

        // Internal columns are never exposed.
        $this->assertArrayNotHasKey('business_id', $response->json('data.0'));
    }

    /** @test */
    public function settings_can_be_updated(): void
    {
        $response = $this->putJson('/api/v1/settings', [
            'receipt_footer' => 'Thank you for your purchase',
            'invoice_prefix' => 'INV',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Settings updated successfully']);

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $this->business->id,
            'key' => 'receipt_footer',
            'value' => 'Thank you for your purchase',
        ]);
    }

    /** @test */
    public function updating_a_setting_twice_upserts_instead_of_duplicating(): void
    {
        $this->putJson('/api/v1/settings', ['receipt_footer' => 'First']);
        $this->putJson('/api/v1/settings', ['receipt_footer' => 'Second']);

        $this->assertSame(1, BusinessSetting::query()
            ->forBusiness($this->business->id)
            ->where('key', 'receipt_footer')
            ->count());

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $this->business->id,
            'key' => 'receipt_footer',
            'value' => 'Second',
        ]);
    }

    /** @test */
    public function patch_updates_the_same_keys_as_put(): void
    {
        $this->patchJson('/api/v1/settings', ['receipt_footer' => 'Patched'])
            ->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $this->business->id,
            'key' => 'receipt_footer',
            'value' => 'Patched',
        ]);
    }

    /** @test */
    public function settings_updates_are_validated(): void
    {
        // Empty body.
        $this->putJson('/api/v1/settings', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['settings']]);

        // Non-string value.
        $this->putJson('/api/v1/settings', ['receipt_footer' => ['not', 'a', 'string']])
            ->assertStatus(422);

        // Over-long value.
        $this->putJson('/api/v1/settings', ['receipt_footer' => str_repeat('a', 2001)])
            ->assertStatus(422);
    }

    /** @test */
    public function a_client_cannot_move_settings_to_another_business(): void
    {
        $this->putJson('/api/v1/settings', [
            'receipt_footer' => 'Hijacked',
            'business_id' => (string) $this->otherBusiness->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('business_settings', ['value' => 'Hijacked']);
        $this->assertDatabaseMissing('business_settings', [
            'business_id' => $this->otherBusiness->id,
            'key' => 'receipt_footer',
        ]);
    }

    /** @test */
    public function business_a_cannot_update_business_b_settings(): void
    {
        BusinessSetting::create([
            'business_id' => $this->otherBusiness->id,
            'key' => 'receipt_footer',
            'value' => 'Theirs',
        ]);

        $this->putJson('/api/v1/settings', ['receipt_footer' => 'Ours now'])
            ->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $this->otherBusiness->id,
            'key' => 'receipt_footer',
            'value' => 'Theirs',
        ]);
    }

    /** @test */
    public function sensitive_settings_are_never_returned(): void
    {
        // Provisioned directly in the table, as an operator would do.
        BusinessSetting::create([
            'business_id' => $this->business->id,
            'key' => 'payment_gateway_api_key',
            'value' => 'sk_live_super_secret_value',
        ]);
        BusinessSetting::create([
            'business_id' => $this->business->id,
            'key' => 'smtp_password',
            'value' => 'hunter2',
        ]);
        BusinessSetting::create([
            'business_id' => $this->business->id,
            'key' => 'receipt_footer',
            'value' => 'Safe value',
        ]);

        $body = (string) $this->getJson('/api/v1/settings')->getContent();

        $this->assertStringNotContainsString('sk_live_super_secret_value', $body);
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('payment_gateway_api_key', $body);
        $this->assertStringContainsString('Safe value', $body);
    }

    /** @test */
    public function sensitive_settings_cannot_be_written_through_the_api(): void
    {
        $this->putJson('/api/v1/settings', ['payment_gateway_api_key' => 'sk_live_abc'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['payment_gateway_api_key']]);

        $this->assertDatabaseMissing('business_settings', ['key' => 'payment_gateway_api_key']);
    }

    /** @test */
    public function reserved_keys_cannot_be_written(): void
    {
        $this->putJson('/api/v1/settings', ['business_id' => (string) $this->otherBusiness->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['business_id']]);

        $this->assertDatabaseMissing('business_settings', [
            'business_id' => $this->otherBusiness->id,
            'key' => 'business_id',
        ]);
    }
}
