<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\AttachRendition;
use App\Actions\ReplaceReleaseTracks;
use App\Actions\SaveArtist;
use App\Actions\SaveRelease;
use App\Actions\UpdateTrackCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use App\Services\PlaybackMedia;
use App\Support\TextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogManagementController extends Controller
{
    public function artists(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return ArtistResource::collection(Artist::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->withCount('tracks')->orderByDesc('id')->paginate(50));
    }

    public function saveArtist(Request $request, SaveArtist $save, ?Artist $artist = null): ArtistResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'sort_name' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'country_code' => ['nullable', 'regex:/^[A-Z]{2}$/'], 'revision' => [$artist?->exists ? 'required' : 'nullable', 'integer', 'min:0']]);
        $artist = $save->handle($request->user(), $data, $artist);

        return new ArtistResource($artist->loadCount('tracks'));
    }

    public function releases(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return ReleaseResource::collection(Release::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->with('artists')->withCount('tracks')->orderByDesc('id')->paginate(50));
    }

    public function showRelease(Release $release): ReleaseResource
    {
        return new ReleaseResource($release->load(['artists', 'tracks'])->loadCount('tracks'));
    }

    public function saveRelease(Request $request, SaveRelease $save, ?Release $release = null): ReleaseResource
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(Release::TYPES)], 'released_on' => ['nullable', 'date_format:Y-m-d'], 'artist_ids' => ['required', 'array', 'min:1', 'max:20'], 'artist_ids.*' => ['required', 'integer', 'distinct', 'exists:artists,id'], 'revision' => [$release?->exists ? 'required' : 'nullable', 'integer', 'min:0']]);
        $release = $save->handle($request->user(), $data, $release);

        return new ReleaseResource($release->load('artists')->loadCount('tracks'));
    }

    public function releaseTracks(Request $request, Release $release, ReplaceReleaseTracks $replace): ReleaseResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'tracks' => ['present', 'array', 'max:500'], 'tracks.*' => ['required', 'array:track_id,disc_number,track_number'], 'tracks.*.track_id' => ['required', 'integer', 'distinct', 'exists:tracks,id'], 'tracks.*.disc_number' => ['required', 'integer', 'between:1,999'], 'tracks.*.track_number' => ['required', 'integer', 'between:1,999']]);
        $slots = collect($data['tracks'])->map(fn ($t) => $t['disc_number'].':'.$t['track_number']);
        if ($slots->unique()->count() !== $slots->count()) {
            throw ValidationException::withMessages(['tracks' => __('شمارهٔ آهنگ در هر دیسک باید یکتا باشد.')]);
        }
        $replace->handle($request->user(), $release, $data);

        return new ReleaseResource($release->refresh()->load(['artists', 'tracks'])->loadCount('tracks'));
    }

    public function tracks(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return TrackResource::collection(Track::query()->when(filled($data['q'] ?? null), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]))->with(['mediaAsset.blob', PlaybackMedia::EAGER, 'artists', 'releases'])->orderByDesc('id')->paginate(50));
    }

    public function updateTrack(Request $request, Track $track, UpdateTrackCatalog $update): TrackResource
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'primary_release_id' => ['nullable', 'integer', 'exists:releases,id'], 'revision' => ['required', 'integer', 'min:0'], 'credits' => ['required', 'array', 'min:1', 'max:30'], 'credits.*' => ['required', 'array:artist_id,role'], 'credits.*.artist_id' => ['required', 'integer', 'exists:artists,id'], 'credits.*.role' => ['required', Rule::in(['primary', 'featured', 'composer', 'producer'])]]);
        $credits = collect($data['credits']);
        if (! $credits->contains('role', 'primary') || $credits->map(fn ($c) => $c['artist_id'].':'.$c['role'])->unique()->count() !== $credits->count()) {
            throw ValidationException::withMessages(['credits' => __('حداقل یک هنرمند اصلی لازم است؛ نقش تکراری برای یک هنرمند مجاز نیست.')]);
        }

        return new TrackResource($update->handle($track, $request->user(), $data)->load(['mediaAsset.blob', PlaybackMedia::EAGER, 'artists', 'releases']));
    }

    public function attachRendition(Request $request, Track $track, AttachRendition $attach)
    {
        $data = $request->validate(['asset_id' => ['required', 'uuid', 'exists:media_assets,id'], 'label' => ['required', 'string', 'max:80', Rule::notIn(['original'])]]);
        $attach->handle($request->user(), $track, $data);

        return response()->json(['data' => ['attached' => true]], 201);
    }
}
