<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Catalog\SaveTrackLyrics;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrackLyricResource;
use App\Models\Track;
use App\Models\TrackLyric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LyricsAdminController extends Controller
{
    public function show(Track $track): JsonResponse
    {
        $lyric = TrackLyric::where('track_id', $track->id)->first();

        return response()->json(['data' => $lyric ? new TrackLyricResource($lyric) : null]);
    }

    public function store(Request $request, Track $track, SaveTrackLyrics $save): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'format' => ['required', Rule::in(TrackLyric::FORMATS)],
            'source' => ['required', Rule::in(TrackLyric::SOURCES)],
            'license_note' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65536'],
        ]);

        $existed = TrackLyric::where('track_id', $track->id)->exists();

        return (new TrackLyricResource($save->handle($request->user(), $track, $data)))
            ->response()
            ->setStatusCode($existed ? 200 : 201);
    }

    public function destroy(Request $request, Track $track, SaveTrackLyrics $save): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $save->erase($request->user(), $track, $data['revision']);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
