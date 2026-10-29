<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Listening\ListeningLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListeningEventController extends Controller
{
    public function grant(Request $request, ListeningLedger $ledger): JsonResponse
    {
        $data = $request->validate(['track_id' => ['required', 'integer', 'min:1'], 'privacy_revision' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $ledger->issue($request->user(), $request->session()->getId(), $data['track_id'], $data['privacy_revision'])]);
    }

    public function store(Request $request, ListeningLedger $ledger): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:2048'], 'kind' => ['required', Rule::in(['progress', 'completed', 'skip'])], 'listened_seconds' => ['required', 'numeric', 'between:0,28800']]);

        return response()->json(['data' => $ledger->record($request->user(), $request->session()->getId(), $data)]);
    }
}
