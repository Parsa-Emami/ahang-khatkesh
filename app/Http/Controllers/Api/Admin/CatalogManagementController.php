<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\UpdateTrackCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\Release;
use App\Models\Track;
use App\Support\TextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogManagementController extends Controller
{
    public function artists(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        return ArtistResource::collection(Artist::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->withCount('tracks')->orderByDesc('id')->paginate(50));
    }

    public function saveArtist(Request $request, ?Artist $artist = null): ArtistResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'sort_name' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'country_code' => ['nullable', 'regex:/^[A-Z]{2}$/'], 'revision' => [$artist?->exists ? 'required' : 'nullable', 'integer', 'min:0']]);
        $artist = DB::transaction(function () use ($request, $artist, $data): Artist {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $model = $artist?->exists ? Artist::whereKey($artist->id)->lockForUpdate()->firstOrFail() : new Artist;
            if ($model->exists) {
                abort_unless((int) $model->revision === (int) $data['revision'], 409, 'اطلاعات هنرمند تغییر کرده است.');
            }
            $model->fill(collect($data)->except('revision')->all());
            $model->revision = ($model->revision ?? 0) + 1;
            $model->save();
            foreach ($model->tracks()->with('artists')->get() as $track) {
                $track->update(['artist' => $track->artists->whereIn('pivot.role', ['primary', 'featured'])->pluck('name')->implode('، '), 'catalog_revision' => $track->catalog_revision + 1]);
            }
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'catalog.artist_saved', 'subject_type' => 'artist', 'subject_id' => (string) $model->id, 'metadata' => ['revision' => $model->revision]]);
            return $model;
        }, 5);
        return new ArtistResource($artist->loadCount('tracks'));
    }

    public function releases(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        return ReleaseResource::collection(Release::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->with('artists')->withCount('tracks')->orderByDesc('id')->paginate(50));
    }

    public function saveRelease(Request $request, ?Release $release = null): ReleaseResource
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(Release::TYPES)], 'released_on' => ['nullable', 'date_format:Y-m-d'], 'artist_ids' => ['required', 'array', 'min:1', 'max:20'], 'artist_ids.*' => ['required', 'integer', 'distinct', 'exists:artists,id'], 'revision' => [$release?->exists ? 'required' : 'nullable', 'integer', 'min:0']]);
        $release = DB::transaction(function () use ($request, $release, $data): Release {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $model = $release?->exists ? Release::whereKey($release->id)->lockForUpdate()->firstOrFail() : new Release;
            if ($model->exists) {
                abort_unless((int) $model->revision === (int) $data['revision'], 409, 'اطلاعات انتشار تغییر کرده است.');
            }
            $oldTitle = $model->title;
            $model->fill(collect($data)->only(['title', 'type', 'released_on'])->all());
            $model->revision = ($model->revision ?? 0) + 1;
            $model->save();
            $model->artists()->detach();
            foreach ($data['artist_ids'] as $i => $id) {
                $model->artists()->attach($id, ['position' => $i + 1]);
            }
            foreach ($model->tracks()->where('primary_release_id', $model->id)->get() as $track) {
                $track->update(['album' => $model->title, 'catalog_revision' => $track->catalog_revision + 1]);
            }
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'catalog.release_saved', 'subject_type' => 'release', 'subject_id' => (string) $model->id, 'metadata' => ['revision' => $model->revision]]);
            return $model;
        }, 5);
        return new ReleaseResource($release->load('artists')->loadCount('tracks'));
    }

    public function releaseTracks(Request $request, Release $release): ReleaseResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'tracks' => ['present', 'array', 'max:500'], 'tracks.*' => ['required', 'array:track_id,disc_number,track_number'], 'tracks.*.track_id' => ['required', 'integer', 'distinct', 'exists:tracks,id'], 'tracks.*.disc_number' => ['required', 'integer', 'between:1,999'], 'tracks.*.track_number' => ['required', 'integer', 'between:1,999']]);
        $slots = collect($data['tracks'])->map(fn ($t) => $t['disc_number'].':'.$t['track_number']);
        if ($slots->unique()->count() !== $slots->count()) {
            throw ValidationException::withMessages(['tracks' => 'شمارهٔ آهنگ در هر دیسک باید یکتا باشد.']);
        }
        DB::transaction(function () use ($request, $release, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $locked = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->revision === (int) $data['revision'], 409, 'اطلاعات انتشار تغییر کرده است.');
            Track::where('primary_release_id', $locked->id)->whereNotIn('id', array_column($data['tracks'], 'track_id'))->get()->each(fn ($track) => $track->update(['primary_release_id' => null, 'album' => null, 'catalog_revision' => $track->catalog_revision + 1]));
            $locked->tracks()->detach();
            foreach ($data['tracks'] as $row) {
                $locked->tracks()->attach($row['track_id'], ['disc_number' => $row['disc_number'], 'track_number' => $row['track_number']]);
            }
            $locked->increment('revision');
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'catalog.release_tracks_updated', 'subject_type' => 'release', 'subject_id' => (string) $release->id, 'metadata' => ['revision' => $locked->revision, 'tracks' => count($data['tracks'])]]);
        }, 5);
        return new ReleaseResource($release->refresh()->load(['artists', 'tracks'])->loadCount('tracks'));
    }

    public function tracks(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        return TrackResource::collection(Track::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->with(['mediaAsset', 'artists', 'releases'])->orderByDesc('id')->paginate(50));
    }

    public function updateTrack(Request $request, Track $track, UpdateTrackCatalog $update): TrackResource
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'primary_release_id' => ['nullable', 'integer', 'exists:releases,id'], 'revision' => ['required', 'integer', 'min:0'], 'credits' => ['required', 'array', 'min:1', 'max:30'], 'credits.*' => ['required', 'array:artist_id,role'], 'credits.*.artist_id' => ['required', 'integer', 'exists:artists,id'], 'credits.*.role' => ['required', Rule::in(['primary', 'featured', 'composer', 'producer'])]]);
        $credits = collect($data['credits']);
        if (! $credits->contains('role', 'primary') || $credits->map(fn ($c) => $c['artist_id'].':'.$c['role'])->unique()->count() !== $credits->count()) {
            throw ValidationException::withMessages(['credits' => 'حداقل یک هنرمند اصلی لازم است؛ نقش تکراری برای یک هنرمند مجاز نیست.']);
        }
        return new TrackResource($update->handle($track, $request->user(), $data)->load(['mediaAsset', 'artists', 'releases']));
    }

    public function attachRendition(Request $request, Track $track)
    {
        $data = $request->validate(['asset_id' => ['required', 'uuid', 'exists:media_assets,id'], 'label' => ['required', 'string', 'max:80', Rule::notIn(['original'])]]);
        DB::transaction(function () use ($request, $track, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $asset = MediaAsset::findOrFail($data['asset_id']);
            $original = $track->mediaAsset;
            if (abs((float) $asset->duration_seconds - (float) $original->duration_seconds) > 1) {
                throw ValidationException::withMessages(['asset_id' => 'طول نسخهٔ صوتی با آهنگ اصلی همخوان نیست.']);
            }
            abort_if(DB::table('track_media')->where('media_asset_id', $asset->id)->exists() || Track::where('media_asset_id', $asset->id)->exists() || DB::table('track_media')->where('track_id', $track->id)->where('label', $data['label'])->exists(), 409, 'فایل یا برچسب قبلاً متصل شده است.');
            DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $asset->id, 'purpose' => 'rendition', 'label' => $data['label']]);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'catalog.rendition_attached', 'subject_type' => 'track', 'subject_id' => (string) $track->id]);
        }, 5);
        return response()->json(['data' => ['attached' => true]], 201);
    }
}
