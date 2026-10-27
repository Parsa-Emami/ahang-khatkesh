<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(UserRole $role = UserRole::Owner): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $user;
    }

    public function test_curator_can_manage_catalog_but_cannot_manage_people_or_read_operations_and_audit(): void
    {
        $this->administrator(UserRole::Curator);
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('data.members', null);
        $this->getJson('/api/v1/admin/artists')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/operations')->assertForbidden();
        $this->getJson('/api/v1/admin/audit-events')->assertForbidden();
    }

    public function test_administrator_cannot_grant_a_privileged_role_or_modify_an_owner(): void
    {
        $admin = $this->administrator(UserRole::Administrator);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create();
        $this->patchJson('/api/v1/admin/users/'.$member->id, ['role' => 'owner'])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$owner->id, ['is_active' => false])->assertForbidden();
        $this->assertSame(UserRole::Owner, $owner->fresh()->role);
        $this->assertSame(UserRole::Administrator, $admin->fresh()->role);
    }

    public function test_last_active_owner_is_preserved_and_self_deactivation_is_refused(): void
    {
        $owner = $this->administrator();
        $this->patchJson('/api/v1/admin/users/'.$owner->id, ['role' => 'listener'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson('/api/v1/admin/users/'.$owner->id, ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(UserRole::Owner, $owner->fresh()->role);
    }

    public function test_expired_step_up_cannot_change_access(): void
    {
        $this->administrator();
        $member = User::factory()->create();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->patchJson('/api/v1/admin/users/'.$member->id, ['role' => 'curator'])->assertStatus(423)->assertJsonPath('code', 'password_confirmation_required');
        $this->assertSame(UserRole::Listener, $member->fresh()->role);
    }

    public function test_revoked_session_version_is_rejected_even_if_a_session_row_survives(): void
    {
        $owner = $this->administrator();
        $owner->increment('access_version');
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_audit_is_cursor_paginated_and_redacts_unapproved_metadata(): void
    {
        $owner = $this->administrator();
        AuditEvent::create(['user_id' => $owner->id, 'action' => 'security.test', 'metadata' => ['revision' => 4, 'password' => 'must-never-leak', 'setup_key' => 'private-key']]);
        $this->getJson('/api/v1/admin/audit-events?action=security.test')->assertOk()->assertJsonPath('data.0.metadata.revision', 4)->assertJsonMissingPath('data.0.metadata.password')->assertJsonMissingPath('data.0.metadata.setup_key')->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor']]);
        $this->getJson('/api/v1/admin/audit-events?action=x%27%20OR%201=1')->assertUnprocessable();
    }
}
