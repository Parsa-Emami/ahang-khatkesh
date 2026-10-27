<?php

namespace App\Actions;

use App\Models\Track;
use App\Support\TextNormalizer;

class RegisterTrack
{
    public function __construct(private EnsureTrackCatalog $catalog) {}

    public function handle(array $row): Track
    {
        $existing = Track::where('media_asset_id', $row['asset_id'])->first();
        if ($existing) {
            return $existing;
        }
        $track = new Track(['media_asset_id' => $row['asset_id'], 'title' => $row['title'], 'artist' => $row['artist'], 'album' => $row['album'], 'search_text' => TextNormalizer::normalize($row['title'].' '.$row['artist'].' '.$row['album'])]);
        $track->saveQuietly();
        $this->catalog->handle($track, $row['artist_id'] ?? null, $row['release_id'] ?? null);

        return $track;
    }
}
