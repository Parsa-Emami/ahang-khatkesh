<?php

namespace Tests\Feature;

use App\Services\MediaProbe;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaProbeTest extends TestCase
{
    public function test_actual_ffprobe_reads_duration_of_local_audio(): void
    {
        Storage::fake('media');
        $samples = str_repeat(pack('v', 0), 1600);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        Storage::disk('media')->put('valid.wav', $wave);
        $result = $this->app->make(MediaProbe::class)->inspect(Storage::disk('media')->path('valid.wav'));
        $this->assertSame(0.1, $result['duration_seconds']);
    }

    public function test_actual_ffprobe_rejects_non_audio_content(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('invalid.wav', 'not an audio file');
        $this->expectException(ValidationException::class);
        $this->app->make(MediaProbe::class)->inspect(Storage::disk('media')->path('invalid.wav'));
    }
}
