<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Architecture-level guarantees for the API layer:
 * standard envelopes, safe error bodies, and untouched web error rendering.
 */
class ApiArchitectureTest extends TestCase
{
    /** @test */
    public function health_endpoint_returns_the_standard_success_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'API is healthy',
                'data' => ['status' => 'ok'],
            ]);
    }

    /** @test */
    public function unknown_api_route_returns_the_standard_not_found_envelope(): void
    {
        $response = $this->getJson('/api/v1/unregistered-endpoint');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found',
                'errors' => [],
            ]);
    }

    /** @test */
    public function api_routes_return_json_even_without_a_json_accept_header(): void
    {
        $response = $this->call('GET', '/api/v1/unregistered-endpoint', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found',
                'errors' => [],
            ]);
    }

    /** @test */
    public function unsupported_methods_return_the_standard_envelope(): void
    {
        $response = $this->putJson('/api/v1/health');

        $response->assertStatus(405)
            ->assertJson([
                'success' => false,
                'message' => 'Method not allowed',
                'errors' => [],
            ]);
    }

    /** @test */
    public function protected_api_routes_return_a_401_envelope_without_a_token(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/api/v1/__architecture-test/protected',
            static fn () => response()->json(['ok' => true]),
        );

        $response = $this->getJson('/api/v1/__architecture-test/protected');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);
    }

    /** @test */
    public function validation_failures_keep_laravel_messages_in_the_envelope(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['email', 'password']]);

        $this->assertStringContainsString('email', (string) $response->json('errors.email.0'));
    }

    /** @test */
    public function unexpected_exceptions_are_sanitised(): void
    {
        Route::get('/api/v1/__architecture-test/failure', static function (): void {
            throw new RuntimeException('SQLSTATE[42S02]: base table or view not found (internal detail)');
        });

        $response = $this->getJson('/api/v1/__architecture-test/failure');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Something went wrong',
                'errors' => [],
            ]);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('internal detail', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    /** @test */
    public function throttled_api_requests_return_the_standard_envelope(): void
    {
        Route::middleware(['api', 'throttle:1,1'])->get(
            '/api/v1/__architecture-test/throttled',
            static fn () => response()->json(['success' => true, 'message' => 'Allowed', 'data' => null]),
        );

        $this->getJson('/api/v1/__architecture-test/throttled')->assertStatus(200);

        $this->getJson('/api/v1/__architecture-test/throttled')
            ->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Too many requests',
                'errors' => [],
            ]);
    }

    /** @test */
    public function web_requests_still_receive_laravel_html_error_pages(): void
    {
        $response = $this->call('GET', '/unregistered-web-page', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response->assertStatus(404);

        $this->assertStringContainsString(
            'text/html',
            (string) $response->headers->get('Content-Type'),
        );
    }

    /** @test */
    public function a_disallowed_cors_origin_receives_no_cors_headers(): void
    {
        // With two or more configured origins the library answers dynamically,
        // so a request from an unlisted origin must receive no allow-origin
        // header at all.
        config(['cors.allowed_origins' => ['https://a.example', 'https://b.example']]);

        $response = $this->withHeaders(['Origin' => 'https://not-allowed.example'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200);

        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'An origin outside CORS_ALLOWED_ORIGINS must not be reflected back.',
        );
    }

    /** @test */
    public function the_configured_origin_is_answered_never_the_requested_one(): void
    {
        // A single configured origin short-circuits to a constant header. It must
        // always be the *configured* origin — never the caller's Origin header —
        // otherwise any site could obtain credentialed access.
        config(['cors.allowed_origins' => ['https://only-allowed.example']]);

        $response = $this->withHeaders(['Origin' => 'https://attacker.example'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200);

        $this->assertSame(
            'https://only-allowed.example',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    /** @test */
    public function cors_headers_never_use_a_wildcard_origin_with_credentials(): void
    {
        // Guards against a deployment setting CORS_ALLOWED_ORIGINS=*. Browsers
        // reject `*` together with credentials, so the API must never emit it.
        config(['cors.allowed_origins' => ['https://allowed.example']]);

        $response = $this->withHeaders(['Origin' => 'https://allowed.example'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200);

        $this->assertNotSame('*', (string) $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @test */
    public function the_cors_origin_list_never_contains_blank_entries(): void
    {
        // A trailing comma or stray whitespace in CORS_ALLOWED_ORIGINS must not
        // turn into an empty origin that silently weakens the allow-list.
        $_ENV['CORS_ALLOWED_ORIGINS'] = ' https://a.example , ,https://b.example,';
        $_SERVER['CORS_ALLOWED_ORIGINS'] = $_ENV['CORS_ALLOWED_ORIGINS'];

        try {
            $origins = require base_path('config/cors.php');

            $this->assertSame(['https://a.example', 'https://b.example'], $origins['allowed_origins']);
        } finally {
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        }
    }

    /** @test */
    public function a_wildcard_cors_origin_is_rejected_at_configuration_time(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
        $_SERVER['CORS_ALLOWED_ORIGINS'] = '*';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/may not contain "\*"/');

            require base_path('config/cors.php');
        } finally {
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        }
    }

    /** @test */
    public function session_cookies_are_secure_by_default_in_production(): void
    {
        // config/session.php resolves `secure` from APP_ENV whenever
        // SESSION_SECURE_COOKIE is absent, so production must never ship an
        // insecure session cookie by omission.
        $source = (string) file_get_contents(base_path('config/session.php'));

        $this->assertStringContainsString(
            "'secure' => env(",
            $source,
        );

        $this->assertStringContainsString(
            "env('APP_ENV', 'production') === 'production'",
            $source,
            'Session cookies must default to secure in production.',
        );
    }

    /** @test */
    public function reverse_proxies_are_not_trusted_unless_configured(): void
    {
        // Rate limiters key on $request->ip(). Trusting every proxy by default
        // would let a spoofed X-Forwarded-For defeat the login throttle, so the
        // allow-list must stay opt-in through TRUSTED_PROXIES.
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("env('TRUSTED_PROXIES')", $source);
        $this->assertStringNotContainsString("trustProxies(at: '*')", $source);
    }
}
