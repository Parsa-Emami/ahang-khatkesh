<?php

namespace App\Services;

use App\Exceptions\MediaNotReady;
use App\Models\MediaAsset;
use App\Models\Track;

class PlaybackMedia
{
    public const EAGER = 'mediaAsset.conversions.output.blob';

    public function ready(MediaAsset $source): ?MediaAsset
    {
        $source->loadMissing('conversions.output.blob');
        $c = $source->conversions->first(fn ($c) => $c->profile_id === ConversionProfile::ID && $c->profile_version === ConversionProfile::VERSION && $c->status === 'ready' && $c->source_sha256 === $source->sha256);
        $asset = $c?->output;

        return $asset && $asset->source_asset_id === $source->id && $asset->codec === 'mp3' && $asset->blob?->state === 'ready' && $asset->blob->sha256 === $asset->sha256 && $asset->blob->size_bytes === $asset->size_bytes && ($c->report['full_decode'] ?? false) && ($c->report['bitrate_bps'] ?? 0) === 320000 ? $asset : null;
    }

    public function select(Track $track, string $quality = 'auto'): MediaAsset
    {
        $source = $track->mediaAsset;
        abort_if($source->blob_id && $source->blob?->state !== 'ready', 404);
        if ($quality === 'mp3-320' || ($quality === 'auto' && $source->isFlac())) {
            return $this->ready($source) ?? throw new MediaNotReady;
        }

        return $source;
    }

    public function assertReady(array $assetIds): void
    {
        $assets = MediaAsset::with('conversions.output.blob')->whereIn('id', $assetIds)->get();
        foreach ($assets as $asset) {
            if ($asset->isFlac() && ! $this->ready($asset)) {
                throw new MediaNotReady;
            }
        }
    }

    public function info(MediaAsset $source): array
    {
        $source->loadMissing('conversions');
        $conversion = $source->conversions->first(fn ($c) => $c->profile_id === ConversionProfile::ID && $c->profile_version === ConversionProfile::VERSION);

        return ['source_codec' => $source->codec ?? ($source->isFlac() ? 'flac' : null), 'playback_profile' => $source->isFlac() ? ConversionProfile::ID : 'original', 'status' => $source->isFlac() ? ($this->ready($source) ? 'ready' : ($conversion?->status ?? 'not_requested')) : 'ready', 'conversion_id' => $conversion?->id];
    }

    public function qualities(Track $track): array
    {
        $source = $track->mediaAsset;
        $base = route('api.tracks.stream', ['track' => $track->id], false);
        $result = [];
        if ($source->isFlac() && ($mp3 = $this->ready($source))) {
            $result[] = ['id' => 'mp3-320', 'label' => 'MP3 · 320 kbps', 'size_bytes' => $mp3->size_bytes, 'mime_type' => 'audio/mpeg', 'stream_url' => $base.'?quality=mp3-320'];
        }
        $result[] = ['id' => 'original', 'label' => $source->isFlac() ? 'FLAC · Lossless' : 'Original', 'size_bytes' => $source->size_bytes, 'mime_type' => $source->mime_type, 'stream_url' => $base.'?quality=original'];

        return $result;
    }
}
