<?php

namespace Tests\Feature;

use App\Actions\Media\BackfillMediaBlobs;
use App\Actions\Media\ReserveStorage;
use App\Enums\UserRole;
use App\Jobs\DeleteUnreferencedMedia;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MediaLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_committed_cover_survives_a_later_cleanup_failure_and_cleanup_is_durable(): void
    {
        config(['queue.connections.database.after_commit' => false]);
        Storage::fake('media');
        $disk = Storage::disk('media');
        $disk->put('covers/old.png', 'old');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $playlist = Playlist::factory()->create(['cover_path' => 'covers/old.png']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2VkAAAAASUVORK5CYII=');
        app(BackfillMediaBlobs::class)->handle(true);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->post('/api/v1/admin/playlists/'.$playlist->id.'/cover', ['reservation_id' => app(ReserveStorage::class)->handle($owner, strlen($png), 'cover')->id, 'revision' => 0, 'cover' => UploadedFile::fake()->createWithContent('cover.png', $png)], ['Accept' => 'application/json'])->assertOk();
        $path = $playlist->fresh()->cover_path;
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotSame('covers/old.png', $path);
        $disk->assertExists($path);
        $this->travel(86401)->seconds();
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('delete')->with('covers/old.png')->once()->andThrow(new RuntimeException('Injected housekeeping failure'));
        Storage::set('media', $proxy);
        try {
            app()->call([new DeleteUnreferencedMedia('covers/old.png'), 'handle']);
            $this->fail('Expected the storage error to reach the retry mechanism.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected housekeeping failure', $error->getMessage());
        } finally {
            Storage::set('media', $disk);
        }
        $disk->assertExists($path);
        $this->assertSame($path, $playlist->fresh()->cover_path);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_cleanup_is_idempotent_and_protects_references_from_trashed_playlists(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('covers/retained.png', 'retained');
        Storage::disk('media')->put('covers/orphan.png', 'orphan');
        config(['music.garbage_retention_seconds' => 0]);
        $playlist = Playlist::factory()->create(['cover_path' => 'covers/retained.png']);
        $playlist->delete();
        app()->call([new DeleteUnreferencedMedia('covers/retained.png'), 'handle']);
        app()->call([new DeleteUnreferencedMedia('covers/orphan.png'), 'handle']);
        app()->call([new DeleteUnreferencedMedia('covers/orphan.png'), 'handle']);
        Storage::disk('media')->assertExists('covers/retained.png');
        Storage::disk('media')->assertMissing('covers/orphan.png');
        $this->assertSame('covers/retained.png', Playlist::withTrashed()->findOrFail($playlist->id)->cover_path);
    }

    public function test_failed_cover_transaction_only_enqueues_cleanup_of_the_uncommitted_candidate(): void
    {
        Storage::fake('media');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        config(['music.garbage_retention_seconds' => 0]);
        $playlist = Playlist::factory()->create(['cover_path' => 'covers/retained.png']);
        Storage::disk('media')->put('covers/retained.png', 'retained');
        app(BackfillMediaBlobs::class)->handle(true);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2VkAAAAASUVORK5CYII=');
        $this->post('/api/v1/admin/playlists/'.$playlist->id.'/cover', ['reservation_id' => app(ReserveStorage::class)->handle($owner, strlen($png), 'cover')->id, 'revision' => 999, 'cover' => UploadedFile::fake()->createWithContent('cover.png', $png)], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertStringContainsString('CleanupUploadFiles', DB::table('jobs')->value('payload'));
        $this->assertStringNotContainsString('DeleteUnreferencedMedia', DB::table('jobs')->value('payload'));
        $this->assertSame('covers/retained.png', $playlist->fresh()->cover_path);
        Storage::disk('media')->assertExists('covers/retained.png');
    }
}
