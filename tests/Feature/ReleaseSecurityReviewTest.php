<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReleaseSecurityReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_security_headers_and_api_cache_policy_are_present(): void
    {
        $this->app['env'] = 'production';
        $response = $this->getJson('/api/v1/session')->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_login_rate_limit_returns_429_after_burst(): void
    {
        RateLimiter::clear('login');
        $email = 'rate-limit@example.test';
        User::factory()->create(['email' => $email, 'password' => 'SecurePassword123']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'WrongPassword999'])->assertStatus(422);
        }
        $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'WrongPassword999'])->assertStatus(429);
    }

    public function test_csrf_token_is_issued_and_browser_journeys_send_it(): void
    {
        $this->getJson('/api/v1/session')->assertOk()->assertJsonStructure(['data' => ['csrf_token']]);
        $core = (string) file_get_contents(base_path('tests/Browser/core.e2e.ts'));
        $this->assertStringContainsString('X-CSRF-TOKEN', $core);
        $f09 = (string) file_get_contents(base_path('tests/Browser/f09.e2e.ts'));
        $this->assertStringContainsString('X-CSRF-TOKEN', $f09);
        $this->assertTrue(
            class_exists(ValidateCsrfToken::class)
            || class_exists(VerifyCsrfToken::class)
        );
    }

    public function test_release_negative_inventory_points_at_existing_test_methods(): void
    {
        $inventory = json_decode((string) file_get_contents(base_path('docs/verification/f16-negative-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('5.0.0', $inventory['asvs_version']);
        $this->assertNotEmpty($inventory['tests']);
        foreach ($inventory['tests'] as $entry) {
            if (! str_contains($entry, '::')) {
                $class = 'Tests\\Feature\\'.$entry;
                $this->assertTrue(class_exists($class), $entry);

                continue;
            }
            [$className, $method] = explode('::', $entry, 2);
            $class = 'Tests\\Feature\\'.$className;
            $this->assertTrue(class_exists($class), $entry);
            $this->assertTrue(method_exists($class, $method), $entry);
        }
        $template = json_decode((string) file_get_contents(base_path('docs/verification/f16-release-evidence.template.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($template['independent_reviewer']['signed']);
        $this->assertContains('independent_reviewer.signed', $template['production_approved_requires']);
    }
}
