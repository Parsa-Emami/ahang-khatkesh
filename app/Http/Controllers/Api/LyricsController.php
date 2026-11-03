<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackLyricResource;
use App\Models\Track;
use App\Models\TrackLyric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LyricsController extends Controller
{
    public function show(Request $request, Track $track): JsonResponse
    {
        abort_unless(Track::query()->visibleTo($request->user())->whereKey($track->id)->exists(), 404);
        $lyric = TrackLyric::where('track_id', $track->id)->first();
        if (! $lyric) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new TrackLyricResource($lyric)]);
    }
}
