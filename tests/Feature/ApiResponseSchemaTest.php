<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class ApiResponseSchemaTest extends TestCase
{
    use CatalogFixtures;
    use LazilyRefreshDatabase;

    private function assertResponseSchema(TestResponse $response, string $path, string $method, string $status): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $schema = $spec['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'];
        $root = json_decode(json_encode(['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'components' => $spec['components']] + $schema, JSON_THROW_ON_ERROR));
        $result = (new CompliantValidator)->validate(json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR), $root);
        $this->assertTrue($result->isValid(), $result->isValid() ? '' : json_encode((new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_UNICODE));
    }

    public function test_session_playlist_track_and_trash_responses_match_the_documented_schema(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create();
        $playlist = Playlist::factory()->create();
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->assertResponseSchema($this->getJson('/api/v1/session')->assertOk(), '/session', 'get', '200');
        $this->assertResponseSchema($this->getJson('/api/v1/playlists/'.$playlist->id)->assertOk(), '/playlists/{playlist}', 'get', '200');
        $this->assertResponseSchema($this->deleteJson('/api/v1/admin/tracks/'.$track->id, ['revision' => 0])->assertOk(), '/admin/tracks/{track}', 'delete', '200');
        $this->assertResponseSchema($this->getJson('/api/v1/admin/trash?type=track')->assertOk(), '/admin/trash', 'get', '200');
        $this->assertResponseSchema($this->postJson('/api/v1/admin/tracks/'.$track->id.'/restore', ['revision' => 1])->assertOk(), '/admin/tracks/{track}/restore', 'post', '200');
    }

    public function test_storage_reservation_and_budget_responses_are_allowlisted(): void
    {
        Storage::fake('media');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $response = $this->postJson('/api/v1/admin/storage-reservations', ['size_bytes' => 1024, 'purpose' => 'audio'])->assertCreated()->assertJsonMissingPath('data.lease_token')->assertJsonMissingPath('data.candidate_path');
        $this->assertResponseSchema($response, '/admin/storage-reservations', 'post', '201');
        $this->assertResponseSchema($this->getJson('/api/v1/admin/storage')->assertOk(), '/admin/storage', 'get', '200');
    }

    public function test_wrong_or_missing_actor_context_cannot_change_a_playlist(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $playlist = Playlist::factory()->create(['name' => 'Retained']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->withHeader('X-Session-Context', '999999:0')->patchJson('/api/v1/admin/playlists/'.$playlist->id, ['revision' => 0, 'name' => 'Wrong actor'])->assertConflict()->assertJsonPath('code', 'session_context_changed');
        $this->withHeader('X-Session-Context', '')->patchJson('/api/v1/admin/playlists/'.$playlist->id, ['revision' => 0, 'name' => 'Old bundle'])->assertStatus(428)->assertJsonPath('code', 'session_context_required');
        $this->assertSame('Retained', $playlist->fresh()->name);
        $this->assertSame(0, (int) $playlist->fresh()->revision);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_allowlist_schema_rejects_an_accidentally_exposed_password(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        $response = $this->getJson('/api/v1/session')->assertOk()->json('data.user');
        $response['password'] = 'accidental-leak';
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')));
        $schema = (object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'components' => $spec->components, '$ref' => '#/components/schemas/User'];
        $this->assertFalse((new CompliantValidator)->validate((object) $response, $schema)->isValid());
    }

    public function test_catalog_workflow_and_proposal_responses_are_allowlisted(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/member-proposals', ['kind' => 'track_metadata', 'idempotency_key' => (string) Str::uuid(), 'payload' => ['track_id' => $track->id, 'title' => 'Suggested title']])->assertCreated();
        $this->assertResponseSchema($proposal, '/member-proposals', 'post', '201');
        $this->assertResponseSchema($this->getJson('/api/v1/member-proposals')->assertOk(), '/member-proposals', 'get', '200');
        $this->authenticateCatalog($owner);
        $this->assertResponseSchema($this->getJson('/api/v1/admin/proposals')->assertOk(), '/admin/proposals', 'get', '200');
        $preview = $this->postJson('/api/v1/admin/catalog-changes/preview', ['operation' => 'proposal', 'proposal_id' => $proposal->json('data.id'), 'revision' => 0])->assertCreated();
        $this->assertResponseSchema($preview, '/admin/catalog-changes/preview', 'post', '201');
        $this->assertResponseSchema($this->commitCatalog($preview->json('data'))->assertOk(), '/admin/catalog-changes/{changeId}/commit', 'post', '200');
        $this->assertResponseSchema($this->rollbackCatalog($preview->json('data'))->assertOk(), '/admin/catalog-changes/{changeId}/rollback', 'post', '200');
        $this->assertResponseSchema($this->getJson('/api/v1/admin/catalog-changes')->assertOk(), '/admin/catalog-changes', 'get', '200');
        $this->assertResponseSchema($this->getJson('/api/v1/admin/catalog-health')->assertOk(), '/admin/catalog-health', 'get', '200');
        $assets = $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 1)->assertJsonMissingPath('meta');
        $this->assertResponseSchema($assets, '/admin/media', 'get', '200');
        $this->assertResponseSchema($this->getJson('/api/v1/admin/workflows')->assertOk(), '/admin/workflows', 'get', '200');
    }

    public function test_listening_responses_match_the_allowlisted_contract(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->create();
        Playlist::factory()->create(['created_by' => $user->id])->tracks()->attach($track->id, ['position' => 0]);
        $this->actingAs($user)->withCredentials();
        $this->assertResponseSchema($this->getJson('/api/v1/listening/settings')->assertOk(), '/listening/settings', 'get', '200');
        $this->assertResponseSchema($this->patchJson('/api/v1/listening/settings', ['revision' => 0, 'history_enabled' => true])->assertOk(), '/listening/settings', 'patch', '200');
        $grant = $this->postJson('/api/v1/listening/plays', ['track_id' => $track->id, 'privacy_revision' => 1])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->assertResponseSchema($grant, '/listening/plays', 'post', '200');
        $this->travel(40)->seconds();
        $event = $this->postJson('/api/v1/listening/events', ['token' => $grant->json('data.token'), 'kind' => 'progress', 'listened_seconds' => 35])->assertOk();
        $this->assertResponseSchema($event, '/listening/events', 'post', '200');
        foreach (['history', 'export', 'mix'] as $endpoint) {
            $this->assertResponseSchema($this->getJson('/api/v1/listening/'.$endpoint)->assertOk(), '/listening/'.$endpoint, 'get', '200');
        }
        $this->assertResponseSchema($this->deleteJson('/api/v1/listening/history/'.$event->json('data.play_id'), ['revision' => 1])->assertOk(), '/listening/history/{eventId}', 'delete', '200');
        $this->assertResponseSchema($this->deleteJson('/api/v1/listening/history', ['revision' => 2])->assertOk(), '/listening/history', 'delete', '200');
    }
}
