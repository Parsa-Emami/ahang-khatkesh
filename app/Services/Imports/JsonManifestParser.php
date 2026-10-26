<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;
use JsonException;

class JsonManifestParser implements ManifestParser
{
    public function parse(string $contents): array
    {
        try {
            $decoded = json_decode(ltrim($contents, "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['manifest' => 'فایل JSON معتبر نیست.']);
        }
        $rows = is_array($decoded) && array_key_exists('tracks', $decoded) ? $decoded['tracks'] : $decoded;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages(['manifest' => 'JSON باید آرایهٔ آهنگ‌ها یا شیء دارای tracks باشد.']);
        }

        return $rows;
    }
}
