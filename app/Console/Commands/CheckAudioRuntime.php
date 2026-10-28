<?php

namespace App\Console\Commands;

use App\Exceptions\ConversionFailure;
use App\Services\AudioTranscoder;
use Illuminate\Console\Command;

class CheckAudioRuntime extends Command
{
    protected $signature = 'media:doctor';

    protected $description = 'Verify the private audio conversion runtime and configured binary pins';

    public function handle(AudioTranscoder $transcoder): int
    {
        try {
            $runtime = $transcoder->runtime();
            $this->line(json_encode(['ok' => true, 'enabled' => config('music.conversions_enabled'), 'platform' => PHP_OS_FAMILY, 'encoder_version' => $runtime['encoder_version'], 'ffmpeg_sha256' => $runtime['ffmpeg_sha256'], 'ffprobe_sha256' => $runtime['ffprobe_sha256']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ConversionFailure $error) {
            $this->line(json_encode(['ok' => false, 'code' => $error->reason], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
