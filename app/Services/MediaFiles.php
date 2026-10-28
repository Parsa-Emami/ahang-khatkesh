<?php

namespace App\Services;

use App\Exceptions\UploadLockBusy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaFiles
{
    public function managed(string $path): bool
    {
        return (bool) preg_match('~^(audio|covers|blobs|staging)/[A-Za-z0-9._/-]+$~D', $path) && ! str_contains($path, '..');
    }

    public function snapshot(string $path): ?array
    {
        if (! $this->managed($path)) {
            return null;
        }
        $absolute = Storage::disk('media')->path($path);
        if (! is_file($absolute) || is_link($absolute)) {
            return null;
        }
        $root = realpath(Storage::disk('media')->path(''));
        $real = realpath($absolute);
        if (! $root || ! $real || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }
        $size = filesize($absolute);
        $hash = hash_file('sha256', $absolute);
        if ($size === false || $hash === false) {
            throw new RuntimeException('Cannot inspect private media.');
        }

        return ['size_bytes' => $size, 'sha256' => $hash];
    }

    public function availableBytes(): int
    {
        $root = Storage::disk('media')->path('');
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new RuntimeException('Private media volume is unavailable.');
        }
        $bytes = disk_free_space($root);
        if ($bytes === false) {
            throw new RuntimeException('Cannot measure private media volume.');
        }

        return (int) $bytes;
    }

    public function withUploadLock(string $id, callable $operation): mixed
    {
        if (! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            throw new RuntimeException('Invalid upload identity.');
        }
        Storage::disk('media')->makeDirectory('.locks');
        $handle = fopen(Storage::disk('media')->path('.locks/'.$id.'.lock'), 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new UploadLockBusy('Upload filesystem lease is busy.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function stage(UploadedFile $file, string $path): void
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if (! $stream) {
            throw new RuntimeException('Cannot open uploaded media.');
        }
        try {
            if (! Storage::disk('media')->put($path, $stream)) {
                throw new RuntimeException('Private media write failed.');
            }
            $stored = fopen(Storage::disk('media')->path($path), 'r+b');
            if (! $stored) {
                throw new RuntimeException('Cannot flush private media.');
            }
            try {
                if (! fflush($stored) || ! fsync($stored)) {
                    throw new RuntimeException('Private media synchronization failed.');
                }
            } finally {
                fclose($stored);
            }
        } finally {
            fclose($stream);
        }
    }

    public function promote(string $staging, string $candidate): void
    {
        $disk = Storage::disk('media');
        $disk->makeDirectory(dirname($candidate));
        if ($disk->exists($candidate) || ! rename($disk->path($staging), $disk->path($candidate))) {
            throw new RuntimeException('Atomic private media promotion failed.');
        }
        $this->removeEmptyStaging($staging);
    }

    public function removeEmptyStaging(string $path): void
    {
        if (! preg_match('~^staging/[a-f0-9-]{36}/input$~D', $path)) {
            return;
        }
        $directory = dirname(Storage::disk('media')->path($path));
        if (is_dir($directory) && count(scandir($directory)) === 2 && ! rmdir($directory)) {
            throw new RuntimeException('Cannot remove empty upload staging directory.');
        }
    }

    public function delete(string $path): void
    {
        if (! $this->managed($path)) {
            throw new RuntimeException('Invalid private media cleanup path.');
        }
        $disk = Storage::disk('media');
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('Private media deletion failed.');
        }
    }
}
