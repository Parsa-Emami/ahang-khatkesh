<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CatalogFixtures
{
    protected function catalogOwner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    protected function authenticateCatalog(User $user, bool $recent = true): void
    {
        $this->actingAs($user)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->subSeconds($recent ? 0 : 3600)->timestamp]);
    }

    protected function catalogBytes(int $sample = 0): string
    {
        $samples = str_repeat(pack('v', $sample), 16000 * 40);

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    }

    protected function catalogAsset(User $owner, int $sample = 0): MediaAsset
    {
        $bytes = $this->catalogBytes($sample);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id, 'path' => 'audio/'.Str::uuid().'.wav', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'duration_seconds' => 40, 'codec' => 'pcm_s16le']);
        $asset->refresh()->load('blob');
        $asset->update(['path' => $asset->blob->path]);
        Storage::disk('media')->put($asset->blob->path, $bytes);

        return $asset->fresh();
    }

    protected function catalogTrack(User $owner, string $title = 'Recording', ?string $identity = null, int $sample = 0): Track
    {
        $track = Track::factory()->create(['title' => $title, 'artist' => 'Independent identity', 'media_asset_id' => $this->catalogAsset($owner, $sample)->id]);
        $track->update(['recording_identity' => $identity, 'recording_provenance' => $identity ? 'studio-register:2026' : null]);

        return $track->fresh()->load('artists');
    }

    protected function catalogPlaylist(User $owner, array $tracks, string $status = 'published'): Playlist
    {
        $playlist = Playlist::factory()->create(['created_by' => $owner->id, 'status' => $status]);
        foreach ($tracks as $position => $track) {
            $playlist->tracks()->attach($track->id, ['position' => $position + 1]);
        }

        return $playlist;
    }

    protected function catalogRow(Track $track, string $title): array
    {
        $track->refresh()->load('artists');

        return ['id' => $track->id, 'revision' => $track->catalog_revision, 'title' => $title, 'primary_release_id' => $track->primary_release_id, 'credits' => $track->artists->map(fn ($artist) => ['artist_id' => $artist->id, 'role' => $artist->pivot->role])->all()];
    }

    protected function previewCatalog(array $input): array
    {
        return $this->postJson('/api/v1/admin/catalog-changes/preview', $input)->assertCreated()->json('data');
    }

    protected function commitCatalog(array $preview)
    {
        return $this->postJson('/api/v1/admin/catalog-changes/'.$preview['id'].'/commit', ['revision' => 0, 'snapshot_hash' => $preview['snapshot_hash']]);
    }

    protected function rollbackCatalog(array $preview)
    {
        return $this->postJson('/api/v1/admin/catalog-changes/'.$preview['id'].'/rollback', ['revision' => 1, 'snapshot_hash' => $preview['snapshot_hash']]);
    }
}
