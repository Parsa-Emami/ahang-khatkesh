<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

class ConversionProfile
{
    public const ID = 'mp3-cbr-320-v1';

    public const VERSION = 1;

    public function reserveBytes(float $duration): int
    {
        return (int) ceil($duration * 40000 * 1.02) + 1048576;
    }

    public function ownership(MediaAsset $source): string
    {
        $link = DB::table('track_media')->where('media_asset_id', $source->id)->first();
        $original = DB::table('tracks')->where('media_asset_id', $source->id)->value('id');

        return hash('sha256', json_encode([$original === null ? null : (int) $original, $link ? [(int) $link->track_id, $link->purpose, $link->label] : null], JSON_THROW_ON_ERROR));
    }

    public function assertSource(MediaAsset $source): void
    {
        abort_if($source->source_asset_id !== null || $source->codec !== 'flac' || ! in_array($source->channels, [1, 2], true), 422, 'این پروفایل فقط FLAC اصلی تک‌کاناله یا استریو را می‌پذیرد.');
        abort_unless($source->duration_seconds > 0 && $source->duration_seconds <= config('music.max_duration_seconds') && $source->blob?->state === 'ready', 409, 'فایل اصلی نیاز به بررسی دارد.');
        $link = DB::table('track_media')->where('media_asset_id', $source->id)->first();
        abort_if($link && $link->purpose !== 'original', 409, 'نسخهٔ مشتق نمی‌تواند اصل تبدیل باشد.');
    }
}
