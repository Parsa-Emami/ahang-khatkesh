<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Media\IngestMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MediaAsset::orderByDesc('id')->paginate(50));
    }

    public function store(UploadMediaRequest $request, IngestMedia $ingest): JsonResponse
    {
        $result = $ingest->handle($request->user(), $request->validated('reservation_id'), $request->file('audio'), 'audio', $request->boolean('reuse_existing', true));

        return response()->json(['data' => $result['asset'], 'duplicate' => $result['duplicate']], $result['duplicate'] ? 200 : 201);
    }
}
