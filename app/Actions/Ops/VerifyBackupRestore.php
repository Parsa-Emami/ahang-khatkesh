<?php

namespace App\Actions\Ops;

use Illuminate\Support\Facades\Storage;

class VerifyBackupRestore
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, mismatches: list<array<string, mixed>>, missing: list<string>}
     */
    public function handle(array $manifest, ?string $mediaRoot = null): array
    {
        $mismatches = [];
        $missing = [];
        foreach ($manifest['media'] ?? [] as $row) {
            $path = (string) ($row['path'] ?? '');
            $absolute = $mediaRoot
                ? rtrim($mediaRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR)
                : Storage::disk('media')->path($path);
            if (! is_file($absolute)) {
                $missing[] = $path;

                continue;
            }
            $sha = hash_file('sha256', $absolute);
            $size = filesize($absolute);
            if ($sha !== ($row['expected_sha256'] ?? null) || (int) $size !== (int) ($row['expected_bytes'] ?? -1)) {
                $mismatches[] = [
                    'path' => $path,
                    'expected_sha256' => $row['expected_sha256'] ?? null,
                    'observed_sha256' => $sha,
                    'expected_bytes' => $row['expected_bytes'] ?? null,
                    'observed_bytes' => $size,
                ];
            }
        }

        return [
            'ok' => $missing === [] && $mismatches === [],
            'missing' => $missing,
            'mismatches' => $mismatches,
        ];
    }
}
