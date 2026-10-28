<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;

class FlacFixture
{
    public static function bytes(int $channels = 2, int $rate = 44100, int $frequency = 440, float $duration = 1): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ahang-flac-');
        try {
            $process = new Process(['ffmpeg', '-hide_banner', '-v', 'error', '-nostdin', '-y', '-f', 'lavfi', '-i', 'sine=frequency='.$frequency.':duration='.$duration, '-ar', (string) $rate, '-ac', (string) $channels, '-c:a', 'flac', '-f', 'flac', $path]);
            $process->setTimeout(15)->mustRun();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    public static function file(int $channels = 2, int $rate = 44100, int $frequency = 440): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('Synthetic source.flac', self::bytes($channels, $rate, $frequency));
    }
}
