<?php

namespace Tests\Unit;

use App\Exceptions\ConversionFailure;
use App\Services\AudioProcessRunner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AudioFrameValidationTest extends TestCase
{
    private function frame(int $rate, bool $padding = false, int $bitrateIndex = 14): string
    {
        $rateIndex = $rate === 44100 ? 0 : 1;
        $length = (int) floor(144 * 320000 / $rate) + (int) $padding;

        return pack('CCCC', 255, 251, ($bitrateIndex << 4) | ($rateIndex << 2) | ((int) $padding << 1), 0).str_repeat("\0", $length - 4);
    }

    private function validate(string $bytes, int $rate): int
    {
        $path = tempnam(sys_get_temp_dir(), 'mp3-frames-');
        try {
            file_put_contents($path, $bytes);
            $runner = new AudioProcessRunner(['parent_pid' => posix_getppid(), 'validation_timeout' => 5]);

            return (new ReflectionMethod($runner, 'validateFrames'))->invoke($runner, $path, $rate);
        } finally {
            unlink($path);
        }
    }

    #[TestWith([44100])]
    #[TestWith([48000])]
    public function test_constant_bitrate_headers_allow_frame_padding(int $rate): void
    {
        $this->assertSame(2, $this->validate($this->frame($rate).$this->frame($rate, true), $rate));
    }

    public function test_mixed_bitrate_frame_is_rejected(): void
    {
        $this->expectException(ConversionFailure::class);
        $this->expectExceptionMessage('non_cbr_output');
        $this->validate($this->frame(44100).$this->frame(44100, false, 13), 44100);
    }

    public function test_truncated_frame_is_rejected(): void
    {
        $this->expectException(ConversionFailure::class);
        $this->expectExceptionMessage('invalid_output');
        $this->validate(substr($this->frame(48000), 0, -1), 48000);
    }
}
