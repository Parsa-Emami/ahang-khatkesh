<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class MediaProbe
{
    public function inspect(string $path): array
    {
        $process = new Process([config('music.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=duration:stream=codec_type:stream_disposition=attached_pic', '-of', 'json', $path]);
        $process->setTimeout(15);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        $duration = (float) ($result['format']['duration'] ?? 0);
        $streams = collect($result['streams'] ?? []);
        if (! $process->isSuccessful() || $duration <= 0 || $duration > config('music.max_duration_seconds') || ! $streams->contains('codec_type', 'audio') || $streams->contains(fn ($s) => $s['codec_type'] === 'video' && empty($s['disposition']['attached_pic']))) {
            throw ValidationException::withMessages(['audio' => 'فایل صوتی قابل پخش نیست؛ ویدیو یا طول بیشتر از چهار ساعت پذیرفته نمی‌شود.']);
        }

        return ['duration_seconds' => round($duration, 3)];
    }
}
