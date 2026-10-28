<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $playlists = Playlist::selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $imports = PlaylistImport::selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return response()->json(['data' => [
            'tracks' => Track::count(), 'published_playlists' => (int) ($playlists['published'] ?? 0), 'draft_playlists' => (int) ($playlists['draft'] ?? 0),
            'processing_imports' => (int) ($imports['processing'] ?? 0) + (int) ($imports['running'] ?? 0), 'failed_imports' => (int) ($imports['failed'] ?? 0),
            'media_bytes' => (int) MediaAsset::sum('size_bytes'),
            'members' => $request->user()->hasPermission(Permission::UsersManage) ? ['total' => User::count(), 'capacity' => LibrarySetting::MAX_USERS] : null,
        ]]);
    }
}
