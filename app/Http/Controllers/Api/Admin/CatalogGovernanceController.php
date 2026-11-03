<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogChangeBatch;
use App\Models\MediaConversion;
use App\Models\MemberProposal;
use App\Models\PlaylistImport;
use App\Models\Track;
use App\Services\Catalog\CatalogHealth;
use App\Services\Catalog\DuplicateDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogGovernanceController extends Controller
{
    public function duplicates(Request $request, DuplicateDetector $detector): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => ['findings' => $detector->findings($data['q'] ?? null)]]);
    }

    public function health(CatalogHealth $health): JsonResponse
    {
        return response()->json(['data' => ['issues' => $health->issues()]]);
    }

    public function workflows(): JsonResponse
    {
        $imports = PlaylistImport::selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $conversions = MediaConversion::selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return response()->json(['data' => [
            'catalog' => [
                'tracks' => Track::count(),
                'trashed_tracks' => Track::onlyTrashed()->count(),
                'next' => 'catalog',
            ],
            'import' => [
                'processing' => (int) ($imports['processing'] ?? 0) + (int) ($imports['running'] ?? 0),
                'failed' => (int) ($imports['failed'] ?? 0),
                'ready' => (int) ($imports['ready'] ?? 0),
                'next' => ((int) ($imports['failed'] ?? 0)) > 0 ? 'imports' : 'imports',
            ],
            'conversion' => [
                'processing' => (int) ($conversions['processing'] ?? 0) + (int) ($conversions['validating'] ?? 0),
                'failed' => (int) ($conversions['failed'] ?? 0),
                'ready' => (int) ($conversions['ready'] ?? 0),
                'next' => 'conversions',
            ],
            'trash' => [
                'tracks' => Track::onlyTrashed()->count(),
                'next' => 'trash',
            ],
            'members' => [
                'pending_proposals' => MemberProposal::where('status', MemberProposal::PENDING)->count(),
                'next' => 'proposals',
            ],
            'audit' => ['next' => 'audit'],
            'operations' => ['next' => 'operations'],
            'batches' => [
                'previewed' => CatalogChangeBatch::where('status', CatalogChangeBatch::PREVIEWED)->count(),
                'committed' => CatalogChangeBatch::where('status', CatalogChangeBatch::COMMITTED)->count(),
            ],
        ]]);
    }
}
