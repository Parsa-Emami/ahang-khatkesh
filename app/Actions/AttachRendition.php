<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\Track;
use App\Models\User;
use App\Services\ConversionProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachRendition
{
    public function __construct(private AuthorizeLibraryWrite $authorize) {}

    public function handle(User $actor, Track $track, array $data): void
    {
        DB::transaction(function () use ($actor, $track, $data): void {
            $actor = $this->authorize->handle($actor);
            $track = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            $asset = MediaAsset::whereKey($data['asset_id'])->lockForUpdate()->firstOrFail();
            abort_if($asset->source_asset_id || $data['label'] === ConversionProfile::ID, 409, __('اتصال مشتق خودکار فقط از مسیر تبدیل معتبر مجاز است.'));
            $original = $track->mediaAsset;
            if (abs((float) $asset->duration_seconds - (float) $original->duration_seconds) > 1) {
                throw ValidationException::withMessages(['asset_id' => __('طول نسخهٔ صوتی با آهنگ اصلی همخوان نیست.')]);
            }
            abort_if(DB::table('track_media')->where('media_asset_id', $asset->id)->exists() || Track::withTrashed()->where('media_asset_id', $asset->id)->exists() || DB::table('track_media')->where('track_id', $track->id)->where('label', $data['label'])->exists(), 409, __('فایل یا برچسب قبلاً متصل شده است.'));
            DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $asset->id, 'purpose' => 'rendition', 'label' => $data['label']]);
            $asset->increment('ownership_revision');
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.rendition_attached', 'subject_type' => 'track', 'subject_id' => (string) $track->id]);
        }, 5);
    }
}
