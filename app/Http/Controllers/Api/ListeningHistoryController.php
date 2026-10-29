<?php

namespace App\Http\Controllers\Api;

use App\Actions\Listening\ChangeListeningPrivacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListeningEventResource;
use App\Services\Listening\ListeningHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListeningHistoryController extends Controller
{
    public function index(Request $request, ListeningHistory $history): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return ListeningEventResource::collection($history->eager($request->user()->fresh())->paginate(30));
    }

    public function export(Request $request, ListeningHistory $history): JsonResponse
    {
        $rows = $history->query($request->user()->fresh())->orderBy('started_at')->orderBy('id')->limit(10000)->get()->map(fn ($row) => ['play_id' => $row->id, 'track_id' => $row->track_id, 'session_pseudonym' => $row->session_pseudonym, 'started_at' => $row->started_at->toISOString(), 'ended_at' => $row->ended_at?->toISOString(), 'listened_seconds' => $row->listened_seconds, 'outcome' => $row->outcome]);

        return response()->json(['format' => 'ahang-listening-v1', 'data' => $rows])->header('Content-Disposition', 'attachment; filename="ahang-listening-history.json"');
    }

    public function destroy(Request $request, ChangeListeningPrivacy $change, ?string $eventId = null): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $change->handle($request->user()->fresh(), $data, $eventId === null, $eventId)->listeningPreferences()]);
    }
}
