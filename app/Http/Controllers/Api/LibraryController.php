<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LibraryController extends Controller
{
    public function like(Request $request, Track $track): JsonResponse
    {
        Gate::authorize('view', $track);
        DB::table('liked_tracks')->insertOrIgnore(['user_id' => $request->user()->id, 'track_id' => $track->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['is_liked' => true]]);
    }

    public function unlike(Request $request, Track $track): JsonResponse
    {
        Gate::authorize('view', $track);
        $request->user()->likedTracks()->detach($track->id);

        return response()->json(['data' => ['is_liked' => false]]);
    }

    public function save(Request $request, Playlist $playlist): JsonResponse
    {
        Gate::authorize('view', $playlist);
        DB::table('saved_playlists')->insertOrIgnore(['user_id' => $request->user()->id, 'playlist_id' => $playlist->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['is_saved' => true]]);
    }

    public function unsave(Request $request, Playlist $playlist): JsonResponse
    {
        Gate::authorize('view', $playlist);
        $request->user()->savedPlaylists()->detach($playlist->id);

        return response()->json(['data' => ['is_saved' => false]]);
    }
}
