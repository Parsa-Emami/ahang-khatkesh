<?php

namespace App\Services;

use App\Models\MediaAsset;

class ImportMediaReadiness
{
    public function __construct(private PlaybackMedia $playback) {}

    public function load(iterable $imports): void
    {
        $imports = collect($imports);
        $ids = $imports->flatMap(fn ($import) => array_column($import->rows, 'asset_id'))->unique()->all();
        $states = MediaAsset::with('conversions.output.blob')->whereIn('id', $ids)->get()->mapWithKeys(fn ($asset) => [$asset->id => $this->playback->info($asset)]);
        foreach ($imports as $import) {
            $import->setAttribute('_media_statuses', $states->only(array_column($import->rows, 'asset_id'))->all());
        }
    }
}
