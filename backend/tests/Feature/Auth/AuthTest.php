<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Test Business',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'email' => 'admin@example.com',
        ]);
    }

    /** @test */
    public function login_with_valid_credentials_returns_token_and_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['token', 'token_type', 'user'],
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token_type' => 'Bearer',
                    'user' => [
                        'id' => $this->user->id,
                        'business_id' => $this->business->id,
                        'email' => 'admin@example.com',
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('password_hash', $response->json('data.user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.user'));
    }

    /** @test */
    public function login_with_wrong_password_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
                'errors' => [],
            ]);
    }

    /** @test */
    public function login_with_non_existing_email_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
                'errors' => [],
            ]);
    }

    /** @test */
    public function login_with_missing_fields_returns_422(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['email', 'password'],
            ]);
    }

    /** @test */
    public function me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);
    }

    /** @test */
    public function me_with_valid_bearer_token_returns_user(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'business_id', 'name', 'email'],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->user->id,
                    'business_id' => $this->business->id,
                    'email' => 'admin@example.com',
                ],
            ]);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
    }

    /** @test */
    public function logout_with_valid_token_returns_200(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logout successful',
            ]);

        $this->assertNull($response->json('data'));
    }

    /** @test */
    public function token_cannot_be_reused_after_logout(): void
    {
        // Log in to obtain a token.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Log out with the token.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        // Clear the in-memory auth guard cache to simulate a fresh HTTP
        // request (each request in production gets a fresh guard instance).
        Auth::forgetGuards();

        // Attempt to reuse the same token — should be rejected.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /** @test */
    public function logout_revokes_only_the_current_token(): void
    {
        $otherDeviceToken = $this->user->createToken('other-device-token')->plainTextToken;
        $currentToken = $this->user->createToken('current-device-token')->plainTextToken;

        // Log out using the current token.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$currentToken,
        ])->postJson('/api/v1/auth/logout')->assertStatus(200);

        Auth::forgetGuards();

        // The revoked token is rejected...
        $this->withHeaders([
            'Authorization' => 'Bearer '.$currentToken,
        ])->getJson('/api/v1/auth/me')->assertStatus(401);

        Auth::forgetGuards();

        // ...while the other device's token keeps working.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$otherDeviceToken,
        ])->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJson(['data' => ['email' => 'admin@example.com']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @test */
    public function logout_without_authentication_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);
    }
}
