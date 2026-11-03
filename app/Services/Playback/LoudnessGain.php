<?php

namespace App\Services\Playback;

class LoudnessGain
{
    public const TARGET_LUFS = -14.0;

    public const MIN_GAIN = 0.25;

    public const MAX_GAIN = 1.0;

    public function factor(?float $loudnessLufs, bool $enabled): float
    {
        if (! $enabled || $loudnessLufs === null) {
            return 1.0;
        }
        $gain = 10 ** ((self::TARGET_LUFS - $loudnessLufs) / 20);

        return max(self::MIN_GAIN, min(self::MAX_GAIN, $gain));
    }
}
