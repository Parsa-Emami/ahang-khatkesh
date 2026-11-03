<?php

namespace App\Http\Controllers\Api;

use App\Actions\Playback\ManageOfflineDownloads;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfflineDownloadResource;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineDownloadController extends Controller
{
    public function index(Request $request, ManageOfflineDownloads $offline): JsonResponse
    {
        $user = $request->user()->fresh();
        $offline->assertEnabled($user);

        return response()->json([
            'data' => [
                'downloads' => OfflineDownloadResource::collection($offline->list($user)),
                'usage' => $offline->usage($user),
            ],
        ]);
    }

    public function store(Request $request, Track $track, ManageOfflineDownloads $offline): JsonResponse
    {
        $row = $offline->grant($request->user(), $track);

        return response()->json(['data' => new OfflineDownloadResource($row)], 201);
    }

    public function destroy(Request $request, Track $track, ManageOfflineDownloads $offline): JsonResponse
    {
        $offline->revoke($request->user(), $track);

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function wipe(Request $request, ManageOfflineDownloads $offline): JsonResponse
    {
        $count = $offline->wipe($request->user());

        return response()->json(['data' => ['revoked_count' => $count]]);
    }
}
