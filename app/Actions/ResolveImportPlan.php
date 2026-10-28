<?php

namespace App\Actions;

use App\Exceptions\ImportPlanChanged;
use App\Models\Artist;
use App\Models\MediaAsset;
use App\Models\Release;
use App\Models\Track;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveImportPlan
{
    public function __construct(private PlaybackMedia $playback) {}

    public function handle(array $rows, bool $preview = false): array
    {
        $ids = array_column($rows, 'asset_id');
        $assets = MediaAsset::with(['blob', 'conversions.output.blob'])->whereIn('id', $ids)->get()->keyBy('id');
        $tracks = Track::withTrashed()->whereIn('media_asset_id', $ids)->get()->keyBy('media_asset_id');
        $links = DB::table('track_media')->whereIn('media_asset_id', $ids)->get()->keyBy('media_asset_id');
        $artists = Artist::whereIn('id', array_column($rows, 'artist_id'))->get()->keyBy('id');
        $releases = Release::whereIn('id', array_column($rows, 'release_id'))->get()->keyBy('id');
        $effective = [];
        $snapshots = [];
        foreach ($rows as $index => $row) {
            $asset = $assets->get($row['asset_id']);
            $track = $tracks->get($row['asset_id']);
            $link = $links->get($row['asset_id']);
            if (! $asset || $asset->source_asset_id || ! $asset->blob_id || $asset->blob?->state !== 'ready' || $asset->blob->sha256 !== $asset->sha256 || $asset->blob->size_bytes !== $asset->size_bytes || $track?->trashed() || ($link && (! $track || (int) $link->track_id !== $track->id || $link->purpose !== 'original'))) {
                if ($preview) {
                    throw ValidationException::withMessages(['tracks.'.$index.'.asset_id' => 'فایل صوتی ردیف '.($index + 1).' در دسترس نیست؛ فایل اصلی معتبر انتخاب کنید.']);
                }
                throw new ImportPlanChanged('رسانهٔ انتخابی دیگر برای این ایمپورت قابل استفاده نیست.');
            }
            $artist = isset($row['artist_id']) ? $artists->get($row['artist_id']) : null;
            $release = isset($row['release_id']) ? $releases->get($row['release_id']) : null;
            if ((isset($row['artist_id']) && ! $artist) || (isset($row['release_id']) && ! $release)) {
                throw new ImportPlanChanged('هویت کاتالوگ تغییر کرده است.');
            }
            $effective[] = array_replace($row, ['title' => $track?->title ?? $row['title'], 'artist' => $track?->artist ?? $artist?->name ?? $row['artist'], 'album' => $track ? $track->album : ($release?->title ?? $row['album'])]);
            $snapshots[] = ['asset' => $asset->id, 'sha256' => $asset->sha256, 'blob_id' => $asset->blob_id, 'track' => $track?->id, 'revision' => $track?->catalog_revision, 'link' => $link ? [$link->track_id, $link->purpose, $link->label] : null, 'artist' => $artist ? [$artist->id, $artist->revision] : null, 'release' => $release ? [$release->id, $release->revision] : null];
        }
        $plan = ['version' => 3, 'rows' => $effective, 'snapshots' => $snapshots];

        return $plan + ['media' => $assets->mapWithKeys(fn ($asset) => [$asset->id => $this->playback->info($asset)])->all(), 'hash' => hash('sha256', json_encode($this->canonicalize($plan), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
