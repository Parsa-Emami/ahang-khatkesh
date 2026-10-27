<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachRendition
{
    public function handle(User $actor, Track $track, array $data): void
    {
        DB::transaction(function () use ($actor, $track, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            abort_unless(User::whereKey($actor->id)->firstOrFail()->hasPermission(Permission::CatalogManage), 403);
            $asset = MediaAsset::findOrFail($data['asset_id']);
            $original = $track->mediaAsset;
            if (abs((float) $asset->duration_seconds - (float) $original->duration_seconds) > 1) {
                throw ValidationException::withMessages(['asset_id' => 'طول نسخهٔ صوتی با آهنگ اصلی همخوان نیست.']);
            }
            abort_if(DB::table('track_media')->where('media_asset_id', $asset->id)->exists() || Track::where('media_asset_id', $asset->id)->exists() || DB::table('track_media')->where('track_id', $track->id)->where('label', $data['label'])->exists(), 409, 'فایل یا برچسب قبلاً متصل شده است.');
            DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $asset->id, 'purpose' => 'rendition', 'label' => $data['label']]);
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.rendition_attached', 'subject_type' => 'track', 'subject_id' => (string) $track->id]);
        }, 5);
    }
}
