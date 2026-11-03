<?php

namespace App\Actions\Media;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveLoudnessMetadata
{
    public function __construct(private AuthorizeLibraryWrite $authorize) {}

    public function handle(User $actor, MediaAsset $asset, array $data): MediaAsset
    {
        return DB::transaction(function () use ($actor, $asset, $data): MediaAsset {
            $actor = $this->authorize->handle($actor);
            $locked = MediaAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'loudness_lufs' => $data['loudness_lufs'],
                'loudness_peak' => $data['loudness_peak'] ?? null,
                'loudness_analyzed_at' => now(),
            ])->save();
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'media.loudness_metadata_saved',
                'subject_type' => 'media_asset',
                'subject_id' => $locked->id,
                'metadata' => ['loudness_lufs' => $locked->loudness_lufs, 'loudness_peak' => $locked->loudness_peak],
            ]);

            return $locked;
        }, 5);
    }
}
