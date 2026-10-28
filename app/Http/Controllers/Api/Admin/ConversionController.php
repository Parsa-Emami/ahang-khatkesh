<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Conversions\ConversionExecution;
use App\Actions\Conversions\RequestConversion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversionResource;
use App\Models\MediaAsset;
use App\Models\MediaConversion;
use App\Services\ConversionProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversionController extends Controller
{
    public function index(Request $request): mixed
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['queued', 'processing', 'validating', 'ready', 'failed', 'needs_review', 'cancelled'])]]);

        return ConversionResource::collection(MediaConversion::with(['source', 'output'])->when(filled($data['status'] ?? null), fn ($q) => $q->where('status', $data['status']))->orderByDesc('id')->cursorPaginate(30));
    }

    public function show(MediaConversion $conversion): ConversionResource
    {
        return new ConversionResource($conversion->load(['source', 'output']));
    }

    public function store(Request $request, MediaAsset $asset, string $profile, RequestConversion $conversions): JsonResponse
    {
        abort_unless($profile === ConversionProfile::ID, 404);
        $data = $request->validate(['source_revision' => ['required', 'integer', 'min:1'], 'profile_version' => ['required', 'integer', 'in:1']]);
        $c = $conversions->handle($request->user(), $asset, $data['source_revision'], $data['profile_version']);

        return response()->json(['data' => new ConversionResource($c->load(['source', 'output']))], $c->wasRecentlyCreated ? 202 : 200);
    }

    public function retry(Request $request, MediaConversion $conversion, ConversionExecution $execution): ConversionResource
    {
        return $this->control($request, $conversion, $execution, true);
    }

    public function cancel(Request $request, MediaConversion $conversion, ConversionExecution $execution): ConversionResource
    {
        return $this->control($request, $conversion, $execution, false);
    }

    private function control(Request $request, MediaConversion $conversion, ConversionExecution $execution, bool $retry): ConversionResource
    {
        $data = $request->validate(['expected_generation' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1']]);

        return new ConversionResource($execution->control($request->user(), $conversion, $data['revision'], $data['expected_generation'], $retry)->load(['source', 'output']));
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => ['enabled' => (bool) config('music.conversions_enabled'), 'profile_id' => ConversionProfile::ID, 'profile_version' => ConversionProfile::VERSION, 'flac_upload_max_bytes' => config('music.max_upload_kilobytes') * 1024, 'regular_upload_max_bytes' => config('music.max_regular_upload_bytes'), 'max_duration_seconds' => config('music.max_duration_seconds'), 'max_output_bytes' => config('music.max_conversion_bytes'), 'max_active_conversions' => config('music.max_active_conversions'), 'bitrate_bps' => 320000, 'retain_original' => true]]);
    }
}
