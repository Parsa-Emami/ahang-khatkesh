<?php

namespace App\Actions\Ops;

use App\Actions\Media\VerifyMedia;
use Illuminate\Support\Facades\DB;

class VerifyUpgrade
{
    public function __construct(private VerifyMedia $media) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $limit = 1000, string $after = ''): array
    {
        $media = $this->media->handle($limit, $after);
        $catalog = [
            'users' => (int) DB::table('users')->count(),
            'tracks' => (int) DB::table('tracks')->count(),
            'playlists' => (int) DB::table('playlists')->count(),
            'likes' => (int) DB::table('liked_tracks')->count(),
            'playback_states' => (int) DB::table('playback_states')->count(),
            'media_assets' => (int) DB::table('media_assets')->count(),
            'media_blobs' => (int) DB::table('media_blobs')->count(),
        ];

        return [
            'ok' => $media['ok'] === true && $media['next_cursor'] === null,
            'catalog' => $catalog,
            'media' => $media,
            'next_cursor' => $media['next_cursor'],
            'continuable' => $media['next_cursor'] !== null,
        ];
    }
}
