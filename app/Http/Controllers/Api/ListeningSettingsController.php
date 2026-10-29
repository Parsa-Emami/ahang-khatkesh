<?php

namespace App\Http\Controllers\Api;

use App\Actions\Listening\ChangeListeningPrivacy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListeningSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->fresh()->listeningPreferences()]);
    }

    public function update(Request $request, ChangeListeningPrivacy $change): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'history_enabled' => ['sometimes', 'required', 'boolean'], 'recommendations_enabled' => ['sometimes', 'required', 'boolean'], 'private_session' => ['sometimes', 'required', 'boolean'], 'retention_days' => ['sometimes', 'required', 'integer', Rule::in([7, 30, 90])]]);

        return response()->json(['data' => $change->handle($request->user(), $data)->listeningPreferences()]);
    }
}
