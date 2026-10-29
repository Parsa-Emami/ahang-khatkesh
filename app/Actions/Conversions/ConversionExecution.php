<?php

namespace App\Actions\Conversions;

use App\Actions\AuthorizeLibraryWrite;
use App\Enums\UserRole;
use App\Exceptions\ConversionFailure;
use App\Jobs\CleanupUploadFiles;
use App\Jobs\TranscodeMedia;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaConversion;
use App\Models\MediaConversionAttempt;
use App\Models\StorageReservation;
use App\Models\Track;
use App\Models\User;
use App\Services\ConversionProfile;
use App\Services\DatabaseQueueContract;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ConversionExecution
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private RequestConversion $requests, private ConversionProfile $profile, private MediaFiles $files, private DatabaseQueueContract $queue) {}

    public function claim(string $id, int $generation, string $token): ?array
    {
        return DB::transaction(function () use ($id, $generation, $token): ?array {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $conversion = MediaConversion::whereKey($id)->lockForUpdate()->first();
            if (! $this->matches($conversion, $generation, $token) || $conversion->status !== 'queued') {
                return null;
            }
            if (! $this->authorized($conversion)) {
                $this->stop($conversion, 'cancelled', 'access_revoked');

                return null;
            }
            if (! $this->sourceMatches($conversion)) {
                $this->stop($conversion, 'needs_review', 'source_changed');

                return null;
            }
            $attempt = $conversion->attempts()->where('generation', $generation)->firstOrFail();
            $reservation = StorageReservation::findOrFail($attempt->reservation_id);
            if ($reservation->purpose !== 'conversion' || ! in_array($reservation->status, ['reserved', 'writing'], true) || ($reservation->status === 'writing' && $reservation->lease_token !== $token)) {
                $this->stop($conversion, 'needs_review', 'reservation_changed');

                return null;
            }
            $conversion->update(['status' => 'processing', 'revision' => $conversion->revision + 1, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(config('music.conversion_lease_seconds'))]);
            $attempt->update(['status' => 'processing']);
            $reservation->update(['status' => 'writing', 'lease_token' => $token, 'expires_at' => $conversion->lease_expires_at]);

            return ['conversion' => $conversion, 'source' => $conversion->source->load('blob'), 'reservation' => $reservation];
        }, 5);
    }

    public function heartbeat(string $id, int $generation, string $token, float $seconds, bool $validating): bool
    {
        return DB::transaction(function () use ($id, $generation, $token, $seconds, $validating): bool {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $c = MediaConversion::whereKey($id)->lockForUpdate()->first();
            if (! $this->matches($c, $generation, $token) || ! in_array($c->status, ['processing', 'validating'], true) || ! $c->lease_expires_at?->isFuture()) {
                return false;
            }
            if (! $this->authorized($c)) {
                $this->stop($c, 'cancelled', 'access_revoked');

                return false;
            }
            if (! $this->sourceMatches($c)) {
                $this->stop($c, 'needs_review', 'source_changed');

                return false;
            }
            $c->update(['status' => $validating ? 'validating' : $c->status, 'progress_seconds' => max($c->progress_seconds, min($seconds, $c->source->duration_seconds)), 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(config('music.conversion_lease_seconds'))]);
            StorageReservation::whereKey($c->attempts()->where('generation', $generation)->value('reservation_id'))->update(['expires_at' => $c->lease_expires_at]);

            return true;
        }, 5);
    }

    public function finalize(string $id, int $generation, string $token, array $result): void
    {
        $reservationId = MediaConversionAttempt::where('conversion_id', $id)->where('generation', $generation)->value('reservation_id');
        $this->files->withUploadLock($reservationId, function () use ($id, $generation, $token, $result, $reservationId): void {
            $reservation = StorageReservation::findOrFail($reservationId);
            $snapshot = $this->files->snapshot($reservation->staging_path);
            if (! $snapshot || $snapshot['size_bytes'] <= 0 || ($result['validated_frames'] ?? 0) < 1 || ($result['codec'] ?? null) !== 'mp3' || $snapshot['sha256'] !== $result['sha256'] || $snapshot['size_bytes'] !== $result['size_bytes'] || $snapshot['size_bytes'] > $reservation->size_bytes || empty($result['full_decode']) || ($result['bitrate_bps'] ?? 0) !== 320000) {
                throw new ConversionFailure('invalid_output');
            }
            if (! $this->heartbeat($id, $generation, $token, 0, true)) {
                throw new ConversionFailure('attempt_revoked');
            }
            try {
                $this->files->promote($reservation->staging_path, $reservation->candidate_path);
            } catch (\Throwable) {
                throw new ConversionFailure('storage_unavailable', true);
            }
            DB::transaction(function () use ($id, $generation, $token, $result, $reservationId, $snapshot): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $c = MediaConversion::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $this->matches($c, $generation, $token) || ! in_array($c->status, ['processing', 'validating'], true) || ! $c->lease_expires_at?->isFuture() || ! $this->authorized($c) || ! $this->sourceMatches($c)) {
                    throw new ConversionFailure('attempt_revoked');
                }
                $reservation = StorageReservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();
                if ($reservation->status !== 'writing' || $reservation->purpose !== 'conversion' || $reservation->lease_token !== $token || ! $reservation->expires_at->isFuture()) {
                    throw new ConversionFailure('attempt_revoked');
                }
                $blob = MediaBlob::where('sha256', $snapshot['sha256'])->where('size_bytes', $snapshot['size_bytes'])->lockForUpdate()->first();
                $duplicate = $blob !== null;
                if ($blob && ($blob->state !== 'ready' || $this->files->snapshot($blob->path) !== $snapshot)) {
                    throw new ConversionFailure('blob_conflict');
                }
                $blob ??= MediaBlob::create($snapshot + ['path' => $reservation->candidate_path, 'mime_type' => 'audio/mpeg', 'state' => 'ready', 'verified_at' => now()]);
                $asset = MediaAsset::create($snapshot + ['blob_id' => $blob->id, 'path' => $blob->path, 'mime_type' => 'audio/mpeg', 'original_name' => 'MP3 320 · '.mb_substr($c->source->original_name, 0, 220), 'duration_seconds' => $result['duration_seconds'], 'codec' => 'mp3', 'sample_rate' => $result['sample_rate'], 'channels' => $result['channels'], 'source_asset_id' => $c->source_asset_id, 'uploaded_by' => $c->requested_by]);
                $track = Track::withTrashed()->where('media_asset_id', $c->source_asset_id)->first();
                if ($track) {
                    $this->attach($track, $asset);
                }
                $c->update(['status' => 'ready', 'revision' => $c->revision + 1, 'output_asset_id' => $asset->id, 'lease_expires_at' => null, 'error_code' => null, 'next_retry_at' => null, 'progress_seconds' => $c->source->duration_seconds, 'report' => ['bitrate_bps' => 320000, 'validated_frames' => $result['validated_frames'], 'validated_source_samples' => $result['validated_source_samples'], 'full_source_decode' => true, 'full_decode' => true, 'encoder_version' => $result['encoder_version'], 'encoder_sha256' => $result['encoder_sha256'], 'probe_sha256' => $result['probe_sha256'], 'profile_version' => ConversionProfile::VERSION, 'source_retained' => true, 'output_bytes' => $asset->size_bytes, 'source_bytes' => $c->source->size_bytes]]);
                $c->attempts()->where('generation', $generation)->update(['status' => 'ready']);
                $reservation->update(['status' => $duplicate ? 'cleanup_pending' : 'consumed', 'lease_token' => null, 'result_asset_id' => $asset->id, 'result_blob_id' => $blob->id]);
                if ($duplicate) {
                    CleanupUploadFiles::dispatch($reservationId)->onConnection('database')->beforeCommit();
                }
                AuditEvent::create(['user_id' => $c->requested_by, 'action' => 'media.conversion_ready', 'subject_id' => $c->id, 'metadata' => ['generation' => $generation, 'size_bytes' => $asset->size_bytes]]);
            }, 5);
        });
    }

    public function attach(Track $track, MediaAsset $asset): void
    {
        $link = DB::table('track_media')->where('media_asset_id', $asset->id)->first();
        if ($link && ((int) $link->track_id !== $track->id || $link->purpose !== 'rendition' || $link->label !== ConversionProfile::ID)) {
            throw new ConversionFailure('ownership_changed');
        }
        if (! $link) {
            if (DB::table('track_media')->where('track_id', $track->id)->where('label', ConversionProfile::ID)->exists()) {
                throw new ConversionFailure('ownership_changed');
            }
            DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $asset->id, 'purpose' => 'rendition', 'label' => ConversionProfile::ID]);
            $asset->increment('ownership_revision');
        }
    }

    public function failed(string $id, int $generation, string $token, ConversionFailure $failure): void
    {
        DB::transaction(function () use ($id, $generation, $token, $failure): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $c = MediaConversion::whereKey($id)->lockForUpdate()->first();
            if (! $this->matches($c, $generation, $token) || ! in_array($c->status, MediaConversion::ACTIVE, true)) {
                return;
            }
            if ($failure->reason === 'worker_busy') {
                $c->update(['status' => 'queued', 'lease_expires_at' => null]);
                TranscodeMedia::dispatch($id, $generation, $token)->onConnection('media_database')->onQueue('media')->delay(now()->addSeconds(15))->beforeCommit();

                return;
            }
            $status = in_array($failure->reason, ['source_changed', 'ownership_changed', 'blob_conflict'], true) ? 'needs_review' : ($failure->reason === 'access_revoked' ? 'cancelled' : 'failed');
            $this->stop($c, $status, $failure->reason);
            if ($failure->transient && $c->automatic_retries < 2 && $this->authorized($c)) {
                $c->update(['next_retry_at' => now()->addSeconds(30 * (2 ** $c->automatic_retries))]);
            }
        }, 5);
    }

    public function control(User $actor, MediaConversion $conversion, int $revision, int $generation, bool $retry): MediaConversion
    {
        return DB::transaction(function () use ($actor, $conversion, $revision, $generation, $retry): MediaConversion {
            $actor = $this->authorize->handle($actor);
            $c = MediaConversion::whereKey($conversion->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->revision === $revision && $c->generation === $generation, 409, __('این تبدیل تغییر کرده است؛ وضعیت تازه را بارگذاری کنید.'));
            if ($retry) {
                abort_unless(in_array($c->status, ['failed', 'needs_review', 'cancelled'], true), 409, __('این تبدیل قابل شروع دوباره نیست.'));
                $c->update(['automatic_retries' => 0]);
                $this->requests->enqueue($actor, $c->source->fresh('blob'), $c);
            } else {
                abort_unless(in_array($c->status, MediaConversion::ACTIVE, true) || $c->next_retry_at, 409, __('این تبدیل پایان یافته است.'));
                $this->stop($c, 'cancelled', 'cancelled_by_owner');
            }
            AuditEvent::create(['user_id' => $actor->id, 'action' => $retry ? 'media.conversion_retried' : 'media.conversion_cancelled', 'subject_id' => $c->id]);

            return $c;
        }, 5);
    }

    public function recover(int $limit = 50): int
    {
        $ids = MediaConversion::where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['processing', 'validating'])->where('lease_expires_at', '<=', now()))->orWhere(fn ($q) => $q->where('status', 'queued')->where('queued_at', '<=', now()->subSeconds(config('music.conversion_queue_stall_seconds'))))->orWhere('next_retry_at', '<=', now()))->orderBy('id')->limit($limit)->pluck('id');
        $recovered = 0;
        foreach ($ids as $id) {
            $recovered += DB::transaction(function () use ($id): int {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $c = MediaConversion::whereKey($id)->lockForUpdate()->firstOrFail();
                $expired = in_array($c->status, ['processing', 'validating'], true) && $c->lease_expires_at?->lessThanOrEqualTo(now());
                $stalled = $c->status === 'queued' && $c->queued_at?->lessThanOrEqualTo(now()->subSeconds(config('music.conversion_queue_stall_seconds')));
                if ($stalled && MediaConversion::whereIn('status', ['processing', 'validating'])->where('lease_expires_at', '>', now())->where('heartbeat_at', '>', now()->subSeconds(30))->exists()) {
                    return 0;
                }
                if (! $expired && ! $stalled && ! $c->next_retry_at?->lessThanOrEqualTo(now())) {
                    return 0;
                }
                if ($expired || $stalled) {
                    $this->failed($c->id, $c->generation, $c->lease_token, new ConversionFailure('worker_lost', true));
                    $c->refresh();
                }
                if ($c->next_retry_at?->lessThanOrEqualTo(now())) {
                    if (! $this->authorized($c)) {
                        $this->stop($c, 'cancelled', 'access_revoked');
                    } elseif (! $this->sourceMatches($c)) {
                        $this->stop($c, 'needs_review', 'source_changed');
                    } else {
                        try {
                            $c->increment('automatic_retries');
                            $this->requests->enqueue(User::findOrFail($c->requested_by), $c->source->fresh('blob'), $c);
                        } catch (HttpExceptionInterface $error) {
                            if (! in_array($error->getStatusCode(), [429, 507], true)) {
                                throw $error;
                            }
                            $c->update(['next_retry_at' => null, 'error_code' => 'capacity_unavailable']);
                        }
                    }
                }

                return 1;
            }, 5);
        }

        return $recovered;
    }

    private function stop(MediaConversion $c, string $status, string $code): void
    {
        $this->queue->verify();
        $c->update(['status' => $status, 'revision' => $c->revision + 1, 'lease_expires_at' => null, 'next_retry_at' => null, 'error_code' => $code]);
        $attempt = $c->attempts()->where('generation', $c->generation)->first();
        if ($attempt) {
            $attempt->update(['status' => $status, 'error_code' => $code]);
            StorageReservation::whereKey($attempt->reservation_id)->whereIn('status', ['reserved', 'writing'])->update(['status' => 'cleanup_pending', 'lease_token' => null]);
            CleanupUploadFiles::dispatch($attempt->reservation_id)->onConnection('database')->beforeCommit();
        }
    }

    private function matches(?MediaConversion $c, int $generation, string $token): bool
    {
        return $c && $c->generation === $generation && $c->lease_token === $token;
    }

    private function authorized(MediaConversion $c): bool
    {
        $user = User::find($c->requested_by);

        return $user && $user->is_active && $user->role === UserRole::Owner && $user->access_version === $c->actor_epoch;
    }

    private function sourceMatches(MediaConversion $c): bool
    {
        $source = $c->source()->with('blob')->first();

        return $source && $source->blob?->state === 'ready' && $source->ownership_revision === $c->source_revision && $source->sha256 === $c->source_sha256 && hash_equals($c->ownership_hash, $this->profile->ownership($source));
    }
}
