<?php

namespace App\Actions\Conversions;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\Media\ReserveStorage;
use App\Jobs\TranscodeMedia;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\MediaConversion;
use App\Models\MediaConversionAttempt;
use App\Models\User;
use App\Services\ConversionProfile;
use App\Services\DatabaseQueueContract;
use App\Services\MediaFiles;
use App\Services\MediaProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestConversion
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private ConversionProfile $profile, private ReserveStorage $storage, private DatabaseQueueContract $queue, private MediaFiles $files, private MediaProbe $probe) {}

    public function handle(User $actor, MediaAsset $source, int $revision, int $profileVersion): MediaConversion
    {
        DB::transaction(fn () => $this->authorize->handle($actor), 5);
        $source = $source->fresh('blob');
        if (! $source->codec) {
            $snapshot = $this->files->snapshot($source->storagePath());
            abort_unless($snapshot && $snapshot['sha256'] === $source->sha256 && $snapshot['size_bytes'] === $source->size_bytes, 409, __('فایل اصلی نیاز به بررسی یکپارچگی دارد.'));
            $facts = $this->probe->inspect(Storage::disk('media')->path($source->storagePath()));
        } else {
            $facts = null;
        }

        return DB::transaction(function () use ($actor, $source, $revision, $profileVersion, $facts): MediaConversion {
            $actor = $this->authorize->handle($actor);
            $source = MediaAsset::with('blob')->whereKey($source->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->ownership_revision === $revision && $profileVersion === ConversionProfile::VERSION, 409, __('نسخهٔ رسانه یا پروفایل تغییر کرده است.'));
            if ($facts) {
                $source->update($facts);
            }

            return $this->fromStoredFacts($actor, $source);
        }, 5);
    }

    public function fromStoredFacts(User $actor, MediaAsset $source): MediaConversion
    {
        $this->queue->verify('media_database');
        abort_unless(config('music.conversions_enabled'), 503, __('سرویس تبدیل صوت هنوز فعال نشده است.'));
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Conversion admission requires the global SQL transaction.');
        }
        $this->profile->assertSource($source);
        $conversion = MediaConversion::where('source_asset_id', $source->id)->where('profile_id', ConversionProfile::ID)->where('profile_version', ConversionProfile::VERSION)->lockForUpdate()->first();
        if ($conversion) {
            return $conversion;
        }
        $this->capacity();
        $conversion = MediaConversion::create(['source_asset_id' => $source->id, 'profile_id' => ConversionProfile::ID, 'profile_version' => ConversionProfile::VERSION, 'status' => 'queued', 'requested_by' => $actor->id, 'actor_epoch' => $actor->access_version, 'source_sha256' => $source->sha256, 'source_revision' => $source->ownership_revision, 'ownership_hash' => $this->profile->ownership($source)]);
        $this->enqueue($actor, $source, $conversion);
        AuditEvent::create(['user_id' => $actor->id, 'action' => 'media.conversion_requested', 'subject_id' => $conversion->id]);

        return $conversion;
    }

    public function capacity(?string $except = null): void
    {
        $active = MediaConversion::where(function ($q): void {
            $q->whereIn('status', MediaConversion::ACTIVE)->orWhereNotNull('next_retry_at');
        })->when($except, fn ($q) => $q->whereKeyNot($except))->count();
        abort_if($active >= config('music.max_active_conversions'), 429, __('صف تبدیل پُر است؛ پس از تکمیل کارهای قبلی دوباره تلاش کنید.'));
    }

    public function enqueue(User $actor, MediaAsset $source, MediaConversion $conversion): void
    {
        $this->queue->verify('media_database');
        $this->profile->assertSource($source);
        $this->capacity($conversion->id);
        $bytes = $this->profile->reserveBytes($source->duration_seconds);
        $reservation = $this->storage->handle($actor, $bytes, 'conversion');
        $token = (string) Str::uuid();
        $reservation->update(['expires_at' => now()->addSeconds(config('music.conversion_queue_stall_seconds') + config('music.conversion_lease_seconds')), 'staging_path' => 'staging/'.$reservation->id.'/input', 'candidate_path' => 'blobs/'.substr(hash('sha256', $reservation->id), 0, 2).'/'.$reservation->id]);
        $conversion->update(['generation' => $conversion->generation + 1, 'revision' => $conversion->revision + 1, 'status' => 'queued', 'lease_token' => $token, 'lease_expires_at' => null, 'heartbeat_at' => null, 'queued_at' => now(), 'progress_seconds' => 0, 'error_code' => null, 'next_retry_at' => null, 'requested_by' => $actor->id, 'actor_epoch' => $actor->access_version, 'source_sha256' => $source->sha256, 'source_revision' => $source->ownership_revision, 'ownership_hash' => $this->profile->ownership($source), 'reserved_bytes' => $bytes]);
        MediaConversionAttempt::create(['conversion_id' => $conversion->id, 'generation' => $conversion->generation, 'reservation_id' => $reservation->id]);
        TranscodeMedia::dispatch($conversion->id, $conversion->generation, $token)->onConnection('media_database')->onQueue('media')->beforeCommit();
    }
}
