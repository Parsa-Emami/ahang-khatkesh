<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocalePreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_listener_can_save_only_own_interface_preferences_without_changing_access(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($member)->withHeader('Accept-Language', 'en-US')->patchJson('/api/v1/me', ['locale' => 'en-US', 'direction' => 'rtl', 'motion' => 'reduced', 'time_zone' => 'Europe/Berlin', 'theme' => 'light', 'role' => 'owner', 'is_active' => false])->assertOk()->assertJsonPath('data.locale', 'en-US')->assertJsonPath('data.direction', 'rtl')->assertJsonPath('data.motion', 'reduced')->assertJsonPath('data.time_zone', 'Europe/Berlin')->assertJsonPath('data.role', 'listener')->assertJsonPath('data.role_label', 'Listener')->assertJsonPath('data.access_version', $member->access_version)->assertJsonPath('data.is_active', true)->assertHeader('Content-Language', 'en-US');

        $this->assertDatabaseHas('users', ['id' => $member->id, 'locale' => 'en-US', 'direction' => 'rtl', 'theme' => 'light']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'locale' => 'fa-IR', 'direction' => 'auto']);
    }

    public function test_unknown_preferences_are_rejected_with_english_field_errors(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->withHeader('Accept-Language', 'en-US')->patchJson('/api/v1/me', ['locale' => 'xx', 'direction' => 'sideways', 'motion' => 'always', 'time_zone' => '../wrong'])->assertUnprocessable()->assertJsonValidationErrors(['locale', 'direction', 'motion', 'time_zone'])->assertJsonPath('errors.locale.0', 'The selected language is invalid.');

        $this->assertDatabaseHas('users', ['id' => $member->id, 'locale' => 'fa-IR', 'direction' => 'auto']);
    }

    public function test_language_header_is_allowlisted_and_does_not_leak_between_requests(): void
    {
        $this->withHeader('Accept-Language', 'en-US')->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonPath('errors.email.0', 'The email field is required.')->assertHeader('Content-Language', 'en-US');
        $this->withHeader('Accept-Language', 'xx-XX')->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonPath('errors.email.0', 'فیلد ایمیل الزامی است.')->assertHeader('Content-Language', 'fa-IR');
    }

    public function test_guest_cannot_write_preferences_and_domain_errors_are_localized(): void
    {
        $this->withHeader('Accept-Language', 'en-US')->patchJson('/api/v1/me', ['locale' => 'en-US'])->assertUnauthorized();
        $member = User::factory()->create(['is_active' => false]);

        $this->actingAs($member)->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/stats')->assertForbidden()->assertJsonPath('message', 'This account is inactive.')->assertHeader('Content-Language', 'en-US');
    }
}
