<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ManifestReader
{
    public function __construct(private JsonManifestParser $json, private CsvManifestParser $csv) {}

    public function read(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['manifest' => __('فایل باید با کدگذاری UTF-8 ذخیره شود.')]);
        }
        $parser = match (strtolower($file->getClientOriginalExtension())) {
            'json' => $this->json, 'csv' => $this->csv, default => throw ValidationException::withMessages(['manifest' => __('فقط JSON و CSV پشتیبانی می‌شوند.')])
        };

        return $parser->parse($contents);
    }
}
