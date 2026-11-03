<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Media\SaveLoudnessMetadata;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoudnessController extends Controller
{
    public function update(Request $request, MediaAsset $asset, SaveLoudnessMetadata $save): JsonResponse
    {
        $data = $request->validate([
            'loudness_lufs' => ['required', 'numeric', 'between:-70,0'],
            'loudness_peak' => ['nullable', 'numeric', 'between:-70,0'],
        ]);
        $asset = $save->handle($request->user(), $asset, $data);

        return response()->json(['data' => [
            'id' => $asset->id,
            'loudness_lufs' => $asset->loudness_lufs !== null ? (float) $asset->loudness_lufs : null,
            'loudness_peak' => $asset->loudness_peak !== null ? (float) $asset->loudness_peak : null,
            'loudness_analyzed_at' => $asset->loudness_analyzed_at?->toISOString(),
            'master_rewritten' => false,
        ]]);
    }
}
