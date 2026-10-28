<?php

namespace App\Services;

use App\Exceptions\ConversionFailure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AudioProcessRunner
{
    private bool $stopped = false;

    public function __construct(private array $contract) {}

    public function run(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_getppid') || ! function_exists('pcntl_signal')) {
            throw new ConversionFailure('unsupported_platform');
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopped = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stopped = true;
        });
        $c = $this->contract;
        $root = realpath($c['root']);
        $source = realpath($c['input']);
        if (! $root || ! $source || is_link($c['input']) || ! str_starts_with($source, $root.'/') || ! preg_match('/^[a-f0-9-]{36}$/D', $c['attempt_id'])) {
            throw new ConversionFailure('unsafe_path');
        }
        $staging = $root.'/staging/'.$c['attempt_id'];
        if (! is_dir($root.'/.locks')) {
            @mkdir($root.'/.locks', 0750, true);
        }
        $lock = fopen($root.'/.locks/'.$c['attempt_id'].'.lock', 'c');
        $global = fopen($root.'/.locks/transcode.lock', 'c');
        if (! $lock || ! $global) {
            throw new ConversionFailure('storage_unavailable', true);
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB) || ! flock($global, LOCK_EX | LOCK_NB)) {
                throw new ConversionFailure('worker_busy', true);
            }
            $this->parent();
            if (filesize($source) !== $c['source_bytes'] || ! hash_equals($c['source_hash'], hash_file('sha256', $source))) {
                throw new ConversionFailure('source_changed');
            }
            if (! is_dir($staging) && ! mkdir($staging, 0750, true)) {
                throw new ConversionFailure('storage_unavailable', true);
            }
            $output = $staging.'/input';
            if (file_exists($output)) {
                throw new ConversionFailure('staging_exists');
            }
            $rate = in_array($c['sample_rate'], [44100, 48000], true) ? $c['sample_rate'] : 44100;
            $header = file_get_contents($source, false, null, 0, 42);
            if (strlen($header) !== 42 || substr($header, 0, 4) !== 'fLaC' || (ord($header[4]) & 127) !== 0 || substr($header, 5, 3) !== "\0\0\x22") {
                throw new ConversionFailure('invalid_source');
            }
            $packed = unpack('Nhigh/Nlow', substr($header, 18, 8));
            $samples = (($packed['high'] & 15) << 32) | $packed['low'];
            $inputRate = $packed['high'] >> 12;
            if ($samples <= 0 || $inputRate !== $c['sample_rate']) {
                throw new ConversionFailure('invalid_source');
            }
            $decoded = (float) $this->execute([$c['ffmpeg'], '-hide_banner', '-v', 'error', '-nostdin', '-xerror', '-err_detect', 'explode', '-protocol_whitelist', 'file,pipe', '-f', 'flac', '-i', $source, '-map', '0:a:0', '-threads', '1', '-c:a', 'pcm_s32le', '-f', 'framemd5', '-'], $c['validation_timeout'], 'source', $output);
            if ((int) $decoded !== $samples) {
                throw new ConversionFailure('truncated_source');
            }
            $this->execute([$c['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-nostdin', '-xerror', '-err_detect', 'explode', '-n', '-progress', 'pipe:1', '-stats_period', '0.5', '-nostats', '-protocol_whitelist', 'file,pipe', '-f', 'flac', '-i', $source, '-map', '0:a:0', '-map_metadata', '-1', '-map_chapters', '-1', '-vn', '-sn', '-dn', '-c:a', 'libmp3lame', '-b:a', '320k', '-abr', '0', '-ar', (string) $rate, '-ac', (string) $c['channels'], '-threads', '1', '-id3v2_version', '3', '-write_xing', '1', '-f', 'mp3', $output], $c['encoder_timeout'], 'encode', $output);
            $this->emit(['event' => 'validating']);
            clearstatcache(true, $output);
            $size = is_file($output) ? filesize($output) : 0;
            if (! $size || $size > $c['max_bytes']) {
                throw new ConversionFailure('invalid_output');
            }
            $probe = json_decode($this->execute([$c['ffprobe'], '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=duration:stream=codec_type,codec_name,sample_rate,channels,bit_rate', '-of', 'json', $output], $c['validation_timeout'], 'probe', $output), true);
            $streams = $probe['streams'] ?? [];
            $audio = $streams[0] ?? [];
            if (count($streams) !== 1 || ($audio['codec_name'] ?? null) !== 'mp3' || (int) ($audio['bit_rate'] ?? 0) !== 320000 || (int) ($audio['sample_rate'] ?? 0) !== $rate || (int) ($audio['channels'] ?? 0) !== $c['channels'] || abs((float) ($probe['format']['duration'] ?? 0) - $c['duration']) > 0.25) {
                throw new ConversionFailure('invalid_output');
            }
            $frames = $this->validateFrames($output, $rate);
            $this->execute([$c['ffmpeg'], '-hide_banner', '-v', 'error', '-nostdin', '-xerror', '-err_detect', 'explode', '-protocol_whitelist', 'file,pipe', '-f', 'mp3', '-i', $output, '-map', '0:a:0', '-threads', '1', '-f', 'null', '-'], $c['validation_timeout'], 'decode', $output);
            $handle = fopen($output, 'r+b');
            if (! $handle || ! fflush($handle) || ! fsync($handle)) {
                throw new ConversionFailure('storage_unavailable', true);
            }
            fclose($handle);
            $this->parent();

            return ['duration_seconds' => (float) $probe['format']['duration'], 'codec' => 'mp3', 'sample_rate' => $rate, 'channels' => $c['channels'], 'size_bytes' => $size, 'sha256' => hash_file('sha256', $output), 'bitrate_bps' => 320000, 'validated_frames' => $frames, 'validated_source_samples' => $samples, 'full_source_decode' => true, 'full_decode' => true];
        } finally {
            flock($global, LOCK_UN);
            flock($lock, LOCK_UN);
            fclose($global);
            fclose($lock);
        }
    }

    private function parent(): void
    {
        if ($this->stopped || posix_getppid() !== $this->contract['parent_pid']) {
            throw new ConversionFailure('parent_stopped', true);
        }
    }

    private function execute(array $argv, int $timeout, string $mode, string $output): string
    {
        $c = $this->contract;
        $process = new Process(array_merge([$c['setsid'], $c['prlimit'], '--as='.$c['memory_bytes'], '--cpu='.($timeout + 5), '--fsize='.$c['max_bytes'], '--'], $argv));
        $process->setTimeout($timeout);
        $process->start();
        $buffer = '';
        $lines = '';
        $sourceTime = 0.0;
        try {
            do {
                $this->parent();
                $running = $process->isRunning();
                $process->checkTimeout();
                $chunk = $process->getIncrementalOutput();
                if ($mode === 'probe') {
                    $buffer .= $chunk;
                    if (strlen($buffer) > 1048576) {
                        throw new ConversionFailure('invalid_output');
                    }
                } elseif (in_array($mode, ['encode', 'source'], true)) {
                    $lines .= $chunk;
                    while (($newline = strpos($lines, "\n")) !== false) {
                        $line = substr($lines, 0, $newline);
                        $lines = substr($lines, $newline + 1);
                        if ($mode === 'source' && preg_match('/^0,\s*-?[0-9]+,\s*-?[0-9]+,\s*([0-9]+),\s*[0-9]+,\s*[a-f0-9]+$/D', trim($line), $match)) {
                            $sourceTime += (int) $match[1];
                        }
                        if ($mode === 'encode' && preg_match('/^out_time_us=([0-9]+)$/D', $line, $match)) {
                            $sourceTime = max($sourceTime, (int) $match[1] / 1000000);
                            if ($mode === 'encode') {
                                $this->emit(['event' => 'progress', 'seconds' => min($c['duration'], $sourceTime)]);
                            }
                        }
                    }
                }
                if (strlen($lines) > 16384) {
                    throw new ConversionFailure('invalid_progress');
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                clearstatcache(true, $output);
                if (is_file($output) && filesize($output) > $c['max_bytes']) {
                    throw new ConversionFailure('output_limit');
                }
                if (! $running) {
                    break;
                }
                usleep(50000);
            } while (true);
            if (! $process->isSuccessful()) {
                throw new ConversionFailure($mode === 'encode' ? 'encoder_failed' : 'invalid_output');
            }
        } catch (ProcessTimedOutException) {
            throw new ConversionFailure('timeout', true);
        } finally {
            if ($process->isRunning()) {
                $pid = $process->getPid();
                if ($pid) {
                    posix_kill(-$pid, SIGTERM);
                }
                $process->stop(0.2, SIGKILL);
                if ($pid) {
                    posix_kill(-$pid, SIGKILL);
                }
            }
        }

        return $mode === 'source' ? (string) $sourceTime : $buffer;
    }

    private function validateFrames(string $path, int $rate): int
    {
        $file = fopen($path, 'rb');
        $deadline = microtime(true) + $this->contract['validation_timeout'];
        try {
            $header = fread($file, 10);
            $start = 0;
            if (substr($header, 0, 3) === 'ID3') {
                $start = 10;
                for ($i = 6; $i < 10; $i++) {
                    if (ord($header[$i]) & 128) {
                        throw new ConversionFailure('invalid_output');
                    }
                    $start += ord($header[$i]) << ((9 - $i) * 7);
                }
            }
            fseek($file, $start);
            $count = 0;
            $size = filesize($path);
            while (ftell($file) < $size) {
                $this->parent();
                if (microtime(true) > $deadline) {
                    throw new ConversionFailure('timeout', true);
                }
                $frame = fread($file, 4);
                if (strlen($frame) !== 4 || ord($frame[0]) !== 255 || (ord($frame[1]) & 254) !== 250 || (ord($frame[2]) >> 4) !== 14 || ((ord($frame[2]) >> 2) & 3) !== ($rate === 44100 ? 0 : 1)) {
                    throw new ConversionFailure('non_cbr_output');
                }
                $length = (int) floor(144 * 320000 / $rate) + ((ord($frame[2]) >> 1) & 1);
                if (ftell($file) + $length - 4 > $size) {
                    throw new ConversionFailure('invalid_output');
                }
                fseek($file, $length - 4, SEEK_CUR);
                $count++;
            }
            if ($count === 0) {
                throw new ConversionFailure('invalid_output');
            }

            return $count;
        } finally {
            fclose($file);
        }
    }

    private function emit(array $event): void
    {
        echo json_encode($event, JSON_THROW_ON_ERROR)."\n";
        flush();
    }
}
