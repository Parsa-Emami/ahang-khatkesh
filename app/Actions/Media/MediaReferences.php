<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Playlist;
use App\Models\StorageReservation;

class MediaReferences
{
    public function blob(string $id): bool
    {
        return MediaAsset::where('blob_id', $id)->exists() || Playlist::withTrashed()->where('cover_blob_id', $id)->exists();
    }

    public function path(string $path, ?string $exceptReservation = null): bool
    {
        $blob = MediaBlob::where('path', $path)->first();
        if (($blob && $this->blob($blob->id)) || MediaAsset::where('path', $path)->exists() || Playlist::withTrashed()->where('cover_path', $path)->exists()) {
            return true;
        }

        return StorageReservation::whereIn('status', StorageReservation::CHARGED)->when($exceptReservation, fn ($query) => $query->where('id', '!=', $exceptReservation))->where(fn ($query) => $query->where('candidate_path', $path)->orWhere('staging_path', $path))->exists();
    }
}
