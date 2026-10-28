<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Media\IngestMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MediaAsset::whereNull('source_asset_id')->with('conversions')->orderByDesc('id')->paginate(50)->through(fn ($asset) => (new MediaAssetResource($asset))->resolve()));
    }

    public function store(UploadMediaRequest $request, IngestMedia $ingest): JsonResponse
    {
        $result = $ingest->handle($request->user(), $request->validated('reservation_id'), $request->file('audio'), 'audio', $request->boolean('reuse_existing', true));

        return response()->json(['data' => new MediaAssetResource($result['asset']->load('conversions')), 'duplicate' => $result['duplicate']], $result['duplicate'] ? 200 : 201);
    }
}
