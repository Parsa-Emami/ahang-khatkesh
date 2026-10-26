<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\TrackResource;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\PrivateMedia;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogController extends Controller
{
    public function playlists(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'mood' => ['nullable', Rule::in(Playlist::MOODS)], 'saved' => ['nullable', 'boolean']]);
        $query = Playlist::visibleTo($request->user())->withCount('tracks')->withExists(['savedBy as is_saved' => fn (Builder $q) => $q->where('users.id', $request->user()->id)]);
        if (! empty($data['q'])) {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]);
        }
        if (! empty($data['mood'])) {
            $query->where('mood', $data['mood']);
        }
        if ($request->boolean('saved')) {
            $query->whereHas('savedBy', fn (Builder $q) => $q->where('users.id', $request->user()->id));
        }

        return PlaylistResource::collection($query->orderByDesc('id')->paginate(24));
    }

    public function show(Request $request, Playlist $playlist): PlaylistResource
    {
        Gate::authorize('view', $playlist);
        $playlist->loadCount('tracks')->loadExists(['savedBy as is_saved' => fn (Builder $q) => $q->where('users.id', $request->user()->id)]);
        $playlist->load(['tracks' => fn ($query) => $query->with('mediaAsset')->withExists(['likedBy as is_liked' => fn (Builder $q) => $q->where('users.id', $request->user()->id)])]);

        return new PlaylistResource($playlist);
    }

    public function tracks(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'liked' => ['nullable', 'boolean']]);
        $query = Track::visibleTo($request->user())->with('mediaAsset')->withExists(['likedBy as is_liked' => fn (Builder $q) => $q->where('users.id', $request->user()->id)]);
        if (! empty($data['q'])) {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($data['q'])]);
        }
        if ($request->boolean('liked')) {
            $query->whereHas('likedBy', fn (Builder $q) => $q->where('users.id', $request->user()->id));
        }

        return TrackResource::collection($query->orderByDesc('id')->paginate(50));
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json(['data' => ['playlists' => Playlist::visibleTo($request->user())->count(), 'tracks' => Track::visibleTo($request->user())->count()]]);
    }

    public function cover(Playlist $playlist, PrivateMedia $media): BinaryFileResponse
    {
        Gate::authorize('view', $playlist);
        abort_unless($playlist->cover_path, 404);

        return response()->file($media->path($playlist->cover_path));
    }
}
