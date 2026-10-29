<?php

namespace App\Http\Controllers\Api;

use App\Actions\Listening\SavePlayback;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlaybackRequest;
use App\Http\Resources\TrackResource;
use App\Models\PlaybackState;
use App\Models\Track;
use App\Services\PlaybackMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $state = PlaybackState::where('user_id', $user->id)->first();
        if (! $state || $user->privateListening()) {
            return response()->json(['data' => ['track_id' => null, 'queue' => [], 'position_seconds' => 0, 'volume' => 0.8, 'shuffle' => false, 'repeat_mode' => 'off', 'revision' => (int) ($state?->revision ?? 0)]]);
        }
        $tracks = Track::visibleTo($request->user())->with(['mediaAsset.blob', PlaybackMedia::EAGER])->withExists(['likedBy as is_liked' => fn (Builder $q) => $q->where('users.id', $request->user()->id)])->whereIn('id', $state->queue)->get()->keyBy('id');
        $ordered = collect($state->queue)->map(fn ($id) => $tracks->get($id))->filter()->values();
        $current = $tracks->get($state->track_id);

        return response()->json(['data' => ['track_id' => $current?->id, 'queue' => TrackResource::collection($ordered), 'position_seconds' => $current ? min($state->position_seconds, $current->mediaAsset->duration_seconds) : 0, 'volume' => $state->volume, 'shuffle' => $state->shuffle, 'repeat_mode' => $state->repeat_mode, 'revision' => $state->revision]]);
    }

    public function update(StorePlaybackRequest $request, SavePlayback $save): JsonResponse
    {
        return response()->json(['data' => $save->handle($request->user(), $request->validated())]);
    }
}
