<?php

namespace App\Support\Lyrics;

use InvalidArgumentException;

class LrcParser
{
    public const MAX_BYTES = 65536;

    public const MAX_SECONDS = 86400.0;

    /**
     * @return array{tags: array<string, string>, lines: list<array{time: float, text: string}>}
     */
    public function parse(string $raw, ?float $durationSeconds = null): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('lyrics_too_large');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $tags = [];
        $timed = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[([a-zA-Z]+):([^\]]*)\]\s*$/u', $line, $match)) {
                $tags[strtolower($match[1])] = trim($match[2]);

                continue;
            }
            if (! preg_match_all('/\[(\d{1,3}):(\d{2})(?:\.(\d{1,3}))?\]/u', $line, $stamps, PREG_SET_ORDER)) {
                continue;
            }
            $plain = trim(preg_replace('/\[\d{1,3}:\d{2}(?:\.\d{1,3})?\]/u', '', $line) ?? '');
            if ($plain === '' || str_contains($plain, '<') || str_contains($plain, '>')) {
                throw new InvalidArgumentException('lyrics_markup_forbidden');
            }
            foreach ($stamps as $stamp) {
                $minutes = (int) $stamp[1];
                $seconds = (int) $stamp[2];
                $fraction = isset($stamp[3]) ? (float) ('0.'.$stamp[3]) : 0.0;
                if ($seconds >= 60) {
                    throw new InvalidArgumentException('lyrics_invalid_timestamp');
                }
                $time = ($minutes * 60) + $seconds + $fraction;
                if ($time < 0 || $time > self::MAX_SECONDS) {
                    throw new InvalidArgumentException('lyrics_timestamp_out_of_range');
                }
                if ($durationSeconds !== null && $time > $durationSeconds + 1.0) {
                    throw new InvalidArgumentException('lyrics_timestamp_past_duration');
                }
                $timed[] = ['time' => round($time, 3), 'text' => $plain];
            }
        }
        usort($timed, fn ($a, $b) => $a['time'] <=> $b['time']);
        $lines = [];
        foreach ($timed as $row) {
            $key = number_format($row['time'], 3, '.', '');
            $lines[$key] = $row;
        }

        return ['tags' => $tags, 'lines' => array_values($lines)];
    }

    public function assertPlain(string $raw): string
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('lyrics_too_large');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (str_contains($text, '<') || str_contains($text, '>')) {
            throw new InvalidArgumentException('lyrics_markup_forbidden');
        }

        return $text;
    }
}
