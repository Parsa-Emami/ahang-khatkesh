<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Playlist;
use App\Services\Listening\ExplainableMix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListeningMixController extends Controller
{
    public function __invoke(Request $request, ExplainableMix $mix): JsonResponse
    {
        $data = $request->validate(['mood' => ['nullable', Rule::in(Playlist::MOODS)], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $result = $mix->handle($request->user()->fresh(), $data['mood'] ?? null, $data['limit'] ?? 20);
        $result['data'] = array_map(fn ($row) => ['track' => new TrackResource($row['track']), 'score' => $row['score'], 'reasons' => $row['reasons']], $result['data']);

        return response()->json($result);
    }
}
