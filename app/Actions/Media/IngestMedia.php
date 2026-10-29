<?php

namespace App\Actions\Media;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\Conversions\RequestConversion;
use App\Actions\UpdatePlaylist;
use App\Jobs\CleanupUploadFiles;
use App\Jobs\DeleteUnreferencedMedia;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Playlist;
use App\Models\StorageReservation;
use App\Models\User;
use App\Services\DatabaseQueueContract;
use App\Services\MediaFiles;
use App\Services\MediaProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class IngestMedia
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private MediaFiles $files, private MediaProbe $probe, private UpdatePlaylist $playlists, private DatabaseQueueContract $queue, private CollectMediaGarbage $garbage, private RequestConversion $conversions) {}

    public function handle(User $actor, string $reservationId, UploadedFile $file, string $purpose = 'audio', bool $reuse = true, ?Playlist $playlist = null, ?int $revision = null): array
    {
        $this->queue->verify();
        $intent = ['purpose' => $purpose, 'reuse' => $reuse, 'playlist_id' => $playlist?->id, 'revision' => $revision];
        $claim = DB::transaction(function () use ($actor, $reservationId, $file, $purpose, $intent): StorageReservation {
            $actor = $this->authorize->handle($actor);
            $reservation = StorageReservation::whereKey($reservationId)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->purpose === $purpose && $reservation->access_version === $actor->access_version && $reservation->size_bytes === $file->getSize(), 409, __('رزرو با فایل یا نشست جاری همخوان نیست.'));
            if ($reservation->result_blob_id) {
                abort_unless($reservation->intent == $intent, 409, __('این رزرو قبلاً برای عملیات دیگری مصرف شده است.'));

                return $reservation;
            }
            abort_unless($reservation->status === 'reserved' && $reservation->expires_at->isFuture(), 409, __('رزرو منقضی یا در حال استفاده است.'));
            $reservation->update(['status' => 'writing', 'lease_token' => (string) Str::uuid(), 'expires_at' => now()->addSeconds(config('music.upload_lease_seconds')), 'staging_path' => 'staging/'.$reservation->id.'/input', 'candidate_path' => 'blobs/'.substr(hash('sha256', $reservation->id), 0, 2).'/'.$reservation->id, 'intent' => $intent]);

            return $reservation;
        }, 5);
        if ($claim->result_blob_id) {
            abort_unless(hash_equals(MediaBlob::findOrFail($claim->result_blob_id)->sha256, hash_file('sha256', $file->getRealPath())), 409, __('محتوای فایل این رزرو تغییر کرده است.'));

            return ['asset' => $claim->result_asset_id ? MediaAsset::findOrFail($claim->result_asset_id) : null, 'blob' => MediaBlob::findOrFail($claim->result_blob_id), 'duplicate' => true, 'replayed' => true];
        }
        try {
            return $this->files->withUploadLock($claim->id, function () use ($actor, $claim, $file, $purpose, $reuse, $playlist, $revision): array {
                $this->assertLease($claim);
                $this->files->stage($file, $claim->staging_path);
                $snapshot = $this->files->snapshot($claim->staging_path);
                if (! $snapshot || $snapshot['size_bytes'] === 0 || $snapshot['size_bytes'] !== $claim->size_bytes) {
                    throw ValidationException::withMessages(['audio' => __('فایل کامل نوشته نشد.')]);
                }
                $metadata = $purpose === 'audio' ? $this->probe->inspect(Storage::disk('media')->path($claim->staging_path)) : [];
                abort_if($purpose === 'audio' && ($metadata['codec'] ?? null) !== 'flac' && $snapshot['size_bytes'] > config('music.max_regular_upload_bytes'), 422, __('سقف فایل غیر FLAC برابر ۱۰۰ MiB است.'));
                $this->assertLease($claim);
                $this->files->promote($claim->staging_path, $claim->candidate_path);

                return DB::transaction(function () use ($actor, $claim, $file, $purpose, $reuse, $playlist, $revision, $snapshot, $metadata): array {
                    $actor = $this->authorize->handle($actor);
                    $this->assertLease($claim);
                    $reservation = StorageReservation::whereKey($claim->id)->lockForUpdate()->firstOrFail();
                    $blob = MediaBlob::where('sha256', $snapshot['sha256'])->where('size_bytes', $snapshot['size_bytes'])->lockForUpdate()->first();
                    $duplicate = $blob !== null;
                    if ($blob) {
                        abort_unless($blob->state === 'ready' && $this->files->snapshot($blob->path) === $snapshot, 409, __('رسانهٔ موجود نیاز به بررسی یکپارچگی دارد.'));
                    } else {
                        $blob = MediaBlob::create($snapshot + ['path' => $claim->candidate_path, 'mime_type' => $file->getMimeType(), 'state' => 'ready', 'verified_at' => now()]);
                    }
                    $asset = null;
                    if ($purpose === 'audio') {
                        $asset = $reuse ? MediaAsset::where('blob_id', $blob->id)->whereNull('source_asset_id')->orderBy('id')->first() : null;
                        if (! $asset) {
                            $asset = MediaAsset::create($metadata + $snapshot + ['blob_id' => $blob->id, 'path' => $blob->path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime_type' => $blob->mime_type, 'uploaded_by' => $actor->id]);
                        }
                        if (! $asset->codec) {
                            $asset->update($metadata);
                        }
                    } else {
                        abort_unless($playlist !== null && $revision !== null, 422);
                        $oldPath = Playlist::whereKey($playlist->id)->value('cover_path');
                        $this->playlists->handle($playlist, $actor, ['revision' => $revision, 'cover_path' => $blob->path, 'cover_blob_id' => $blob->id]);
                        if ($oldPath && $oldPath !== $blob->path) {
                            $this->garbage->trackPath($oldPath);
                            DeleteUnreferencedMedia::dispatch($oldPath)->onConnection('database')->delay(now()->addSeconds(config('music.garbage_retention_seconds')))->beforeCommit();
                        }
                    }
                    $reservation->update(['status' => $duplicate ? 'cleanup_pending' : 'consumed', 'result_asset_id' => $asset?->id, 'result_blob_id' => $blob->id]);
                    if ($duplicate) {
                        CleanupUploadFiles::dispatch($reservation->id)->onConnection('database')->beforeCommit();
                    }
                    if ($asset?->isFlac() && config('music.conversions_enabled')) {
                        $this->conversions->fromStoredFacts($actor, $asset->load('blob'));
                    }
                    AuditEvent::create(['user_id' => $actor->id, 'action' => $purpose === 'audio' ? 'media.uploaded' : 'playlist.cover_uploaded', 'subject_id' => $asset?->id ?? (string) $playlist->id, 'metadata' => ['size_bytes' => $blob->size_bytes]]);

                    return ['asset' => $asset, 'blob' => $blob, 'duplicate' => $duplicate, 'replayed' => false];
                }, 5);
            });
        } catch (Throwable $error) {
            DB::transaction(function () use ($claim): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $changed = StorageReservation::whereKey($claim->id)->where('status', 'writing')->where('lease_token', $claim->lease_token)->update(['status' => 'cleanup_pending', 'lease_token' => null]);
                if ($changed) {
                    CleanupUploadFiles::dispatch($claim->id)->onConnection('database')->beforeCommit();
                }
            }, 5);
            throw $error;
        }
    }

    private function assertLease(StorageReservation $snapshot): void
    {
        abort_unless(StorageReservation::whereKey($snapshot->id)->where('status', 'writing')->where('lease_token', $snapshot->lease_token)->where('expires_at', '>', now())->exists(), 409, __('مهلت یا مالکیت آپلود تغییر کرده است.'));
    }
}
