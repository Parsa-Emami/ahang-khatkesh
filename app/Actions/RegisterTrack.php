<?php

namespace App\Actions;

use App\Actions\Conversions\ConversionExecution;
use App\Exceptions\ImportPlanChanged;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\Track;
use App\Services\ConversionProfile;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

class RegisterTrack
{
    public function __construct(private EnsureTrackCatalog $catalog, private ConversionExecution $conversions) {}

    public function handle(array $row): Track
    {
        return DB::transaction(function () use ($row): Track {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $asset = MediaAsset::whereKey($row['asset_id'])->lockForUpdate()->first();
            $existing = Track::withTrashed()->where('media_asset_id', $row['asset_id'])->lockForUpdate()->first();
            $link = DB::table('track_media')->where('media_asset_id', $row['asset_id'])->first();
            if (! $asset || $asset->source_asset_id || ($existing?->trashed() || $existing?->merged_into_id !== null) || ($link && (! $existing || (int) $link->track_id !== $existing->id || $link->purpose !== 'original'))) {
                throw new ImportPlanChanged(__('مالکیت رسانه تغییر کرده است؛ پیش‌نمایش تازه بسازید.'));
            }
            if ($existing) {
                return $existing;
            }
            $track = new Track(['media_asset_id' => $row['asset_id'], 'title' => $row['title'], 'artist' => $row['artist'], 'album' => $row['album'], 'search_text' => TextNormalizer::normalize($row['title'].' '.$row['artist'].' '.$row['album'])]);
            $track->saveQuietly();
            $this->catalog->handle($track, $row['artist_id'] ?? null, $row['release_id'] ?? null);
            $ready = $asset->conversions()->where('status', 'ready')->where('profile_id', ConversionProfile::ID)->with('output')->first();
            if ($ready?->output) {
                $this->conversions->attach($track, $ready->output);
            }

            return $track;
        }, 5);
    }
}
