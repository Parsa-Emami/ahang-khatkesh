<?php

namespace App\Services;

use App\Exceptions\ConversionFailure;
use App\Models\MediaAsset;
use App\Models\StorageReservation;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AudioTranscoder
{
    public function runtime(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_getppid') || ! function_exists('pcntl_signal')) {
            throw new ConversionFailure('unsupported_platform');
        }
        $finder = new ExecutableFinder;
        $result = [];
        foreach (['ffmpeg', 'ffprobe', 'setsid', 'prlimit'] as $name) {
            $configured = config('music.'.$name);
            $path = str_contains($configured, DIRECTORY_SEPARATOR) ? $configured : $finder->find($configured);
            if (! $path || ! is_executable($path)) {
                throw new ConversionFailure('encoder_unavailable', true);
            }
            $result[$name] = realpath($path);
            if (in_array($name, ['ffmpeg', 'ffprobe'], true)) {
                $hash = hash_file('sha256', $path);
                $pin = config('music.'.$name.'_sha256');
                if (($pin && ! hash_equals($pin, $hash)) || (app()->isProduction() && ! $pin)) {
                    throw new ConversionFailure('encoder_build_changed');
                }
                $result[$name.'_sha256'] = $hash;
            }
        }
        $version = new Process([$result['ffmpeg'], '-version']);
        $version->setTimeout(5)->run();
        if (! $version->isSuccessful()) {
            throw new ConversionFailure('encoder_unavailable', true);
        }
        $encoders = new Process([$result['ffmpeg'], '-hide_banner', '-encoders']);
        $encoders->setTimeout(5)->run();
        if (! $encoders->isSuccessful() || ! str_contains($encoders->getOutput(), 'libmp3lame')) {
            throw new ConversionFailure('encoder_unavailable', true);
        }
        $result['encoder_version'] = mb_substr(explode("\n", $version->getOutput())[0], 0, 200);

        return $result;
    }

    public function convert(MediaAsset $source, StorageReservation $reservation, callable $heartbeat): array
    {
        $runtime = $this->runtime();
        $command = [$runtime['setsid'], PHP_BINARY];
        if (str_contains(basename(PHP_BINARY), 'frankenphp')) {
            $command[] = 'php-cli';
        }
        $command[] = base_path('scripts/media-process.php');
        $contract = $runtime + ['root' => Storage::disk('media')->path(''), 'input' => Storage::disk('media')->path($source->storagePath()), 'attempt_id' => $reservation->id, 'source_hash' => $source->sha256, 'source_bytes' => $source->size_bytes, 'sample_rate' => $source->sample_rate, 'channels' => $source->channels, 'duration' => $source->duration_seconds, 'parent_pid' => getmypid(), 'max_bytes' => $reservation->size_bytes, 'memory_bytes' => config('music.conversion_memory_bytes'), 'encoder_timeout' => config('music.encoder_timeout_seconds'), 'validation_timeout' => config('music.validation_timeout_seconds')];
        $process = new Process($command, base_path());
        $process->setInput(json_encode($contract, JSON_THROW_ON_ERROR));
        $process->setTimeout(config('music.encoder_timeout_seconds') + 4 * config('music.validation_timeout_seconds') + 20);
        $process->start();
        $buffer = '';
        $result = null;
        $failure = null;
        $progress = 0.0;
        $validating = false;
        $lastBeat = 0.0;
        try {
            do {
                $running = $process->isRunning();
                $process->checkTimeout();
                $buffer .= $process->getIncrementalOutput();
                $process->clearOutput();
                $process->clearErrorOutput();
                if (strlen($buffer) > 65536) {
                    throw new ConversionFailure('invalid_progress');
                }
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $event = json_decode(substr($buffer, 0, $newline), true, flags: JSON_THROW_ON_ERROR);
                    $buffer = substr($buffer, $newline + 1);
                    if (($event['event'] ?? '') === 'progress') {
                        $progress = max($progress, min($source->duration_seconds, (float) $event['seconds']));
                    } elseif (($event['event'] ?? '') === 'validating') {
                        $validating = true;
                    } elseif (($event['event'] ?? '') === 'complete') {
                        $result = $event['result'];
                    } elseif (($event['event'] ?? '') === 'failed') {
                        $failure = new ConversionFailure($event['code'], (bool) $event['transient']);
                    }
                }
                if (microtime(true) - $lastBeat >= 5 || $validating) {
                    if (! $heartbeat($progress, $validating)) {
                        throw new ConversionFailure('attempt_revoked');
                    }
                    $validating = false;
                    $lastBeat = microtime(true);
                }
                if (! $running) {
                    break;
                }
                usleep(50000);
            } while (true);
            if ($failure) {
                throw $failure;
            }
            if (! $result || ! $process->isSuccessful()) {
                throw new ConversionFailure('runner_failed', true);
            }

            return $result + ['encoder_version' => $runtime['encoder_version'], 'encoder_sha256' => $runtime['ffmpeg_sha256'], 'probe_sha256' => $runtime['ffprobe_sha256']];
        } finally {
            if ($process->isRunning()) {
                $process->stop(2, SIGKILL);
            }
        }
    }
}
