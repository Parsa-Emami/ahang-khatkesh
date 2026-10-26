<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

class PrivateMedia
{
    public function path(string $relative): string
    {
        $root = realpath(Storage::disk('media')->path(''));
        try {
            $path = realpath(Storage::disk('media')->path($relative));
        } catch (PathTraversalDetected) {
            abort(404);
        }
        abort_unless($root !== false && $path !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);

        return $path;
    }
}
