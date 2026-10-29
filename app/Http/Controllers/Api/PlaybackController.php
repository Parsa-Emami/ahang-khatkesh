<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlaybackRequest;
use App\Http\Resources\TrackResource;
use App\Models\LibrarySetting;
use App\Models\PlaybackState;
use App\Models\Track;
use App\Services\PlaybackMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaybackController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $state = PlaybackState::where('user_id', $request->user()->id)->first();
        if (! $state) {
            return response()->json(['data' => ['track_id' => null, 'queue' => [], 'position_seconds' => 0, 'volume' => 0.8, 'shuffle' => false, 'repeat_mode' => 'off', 'revision' => 0]]);
        }
        $tracks = Track::visibleTo($request->user())->with(['mediaAsset.blob', PlaybackMedia::EAGER])->withExists(['likedBy as is_liked' => fn (Builder $q) => $q->where('users.id', $request->user()->id)])->whereIn('id', $state->queue)->get()->keyBy('id');
        $ordered = collect($state->queue)->map(fn ($id) => $tracks->get($id))->filter()->values();
        $current = $tracks->get($state->track_id);

        return response()->json(['data' => ['track_id' => $current?->id, 'queue' => TrackResource::collection($ordered), 'position_seconds' => $current ? min($state->position_seconds, $current->mediaAsset->duration_seconds) : 0, 'volume' => $state->volume, 'shuffle' => $state->shuffle, 'repeat_mode' => $state->repeat_mode, 'revision' => $state->revision]]);
    }

    public function update(StorePlaybackRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tracks = Track::visibleTo($request->user())->with(['mediaAsset.blob', PlaybackMedia::EAGER])->whereIn('id', $data['queue'])->get()->keyBy('id');
        if ($tracks->count() !== count($data['queue']) || ($data['track_id'] !== null && ! in_array($data['track_id'], $data['queue'], true))) {
            throw ValidationException::withMessages(['queue' => __('صف پخش شامل آهنگ نامعتبر یا منتشرنشده است.')]);
        }
        $current = $tracks->get($data['track_id']);
        $data['position_seconds'] = $current ? min($data['position_seconds'], $current->mediaAsset->duration_seconds) : 0;
        $state = DB::transaction(function () use ($request, $data): PlaybackState {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $state = PlaybackState::firstOrCreate(['user_id' => $request->user()->id], ['queue' => [], 'revision' => 0]);
            abort_unless($state->revision === $data['revision'], 409, __('وضعیت پخش در دستگاه دیگری تغییر کرده است. صفحه را تازه کنید.'));
            $state->update(array_replace($data, ['revision' => $state->revision + 1]));

            return $state;
        }, 5);

        return response()->json(['data' => ['revision' => $state->revision]]);
    }
}
