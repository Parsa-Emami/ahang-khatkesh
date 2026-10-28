<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

class AudioFixture
{
    public static function bytes(): string
    {
        $samples = str_repeat(pack('v', 0), 1600);

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    }

    public static function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sample.wav', self::bytes());
    }
}
