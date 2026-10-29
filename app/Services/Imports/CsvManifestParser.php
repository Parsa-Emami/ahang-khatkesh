<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;

class CsvManifestParser implements ManifestParser
{
    public function parse(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, ltrim($contents, "\xEF\xBB\xBF"));
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($header)) {
                throw ValidationException::withMessages(['manifest' => __('فایل CSV خالی است.')]);
            }
            $header = array_map(fn ($v) => trim((string) $v), $header);
            if (count($header) !== count(array_unique($header)) || array_diff(['asset_id', 'title', 'artist'], $header)) {
                throw ValidationException::withMessages(['manifest' => __('ستون‌های asset_id,title,artist الزامی و نام ستون‌ها باید یکتا باشند.')]);
            }
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header)) {
                    throw ValidationException::withMessages(['manifest' => __('تعداد ستون‌های ردیف :row با سربرگ برابر نیست.', ['row' => count($rows) + 2])]);
                }
                $rows[] = array_combine($header, $row);
                if (count($rows) > config('music.max_tracks_per_import')) {
                    break;
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
