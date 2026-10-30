<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CatalogChangeSet;
use App\Models\LibrarySetting;
use App\Models\MediaConversion;
use App\Models\MemberProposal;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $workflows = [];
        if ($user->hasPermission(Permission::CatalogManage)) {
            $workflows[] = ['id' => 'catalog', 'count' => Track::canonical()->count(), 'attention' => Playlist::where('status', Playlist::DRAFT)->count(), 'next' => 'catalog'];
            $workflows[] = ['id' => 'changes', 'count' => CatalogChangeSet::where('user_id', $user->id)->where('status', 'committed')->count(), 'attention' => CatalogChangeSet::where('user_id', $user->id)->where('status', 'previewed')->where('expires_at', '>', now())->count(), 'next' => 'bulk'];
            $workflows[] = ['id' => 'proposals', 'count' => MemberProposal::count(), 'attention' => MemberProposal::where('status', 'pending')->count(), 'next' => 'proposals'];
            $workflows[] = ['id' => 'trash', 'count' => Track::onlyTrashed()->count() + Playlist::onlyTrashed()->count(), 'attention' => 0, 'next' => 'trash'];
        }
        if ($user->hasPermission(Permission::ImportsManage)) {
            $counts = PlaylistImport::groupBy('status')->selectRaw('status, count(*) as aggregate')->pluck('aggregate', 'status');
            $workflows[] = ['id' => 'imports', 'count' => array_sum($counts->all()), 'attention' => (int) ($counts['needs_review'] ?? 0) + (int) ($counts['failed'] ?? 0), 'next' => 'imports'];
        }
        if ($user->hasPermission(Permission::MediaManage)) {
            $counts = MediaConversion::groupBy('status')->selectRaw('status, count(*) as aggregate')->pluck('aggregate', 'status');
            $workflows[] = ['id' => 'conversions', 'count' => array_sum($counts->all()), 'attention' => (int) ($counts['needs_review'] ?? 0) + (int) ($counts['failed'] ?? 0), 'next' => 'conversions'];
        }
        if ($user->hasPermission(Permission::UsersManage)) {
            $workflows[] = ['id' => 'users', 'count' => User::count(), 'attention' => User::where('is_active', false)->count(), 'capacity' => LibrarySetting::MAX_USERS, 'next' => 'users'];
        }
        if ($user->hasPermission(Permission::AuditRead)) {
            $workflows[] = ['id' => 'audit', 'count' => AuditEvent::count(), 'attention' => 0, 'next' => 'audit'];
        }
        if ($user->hasPermission(Permission::OperationsRead)) {
            $workflows[] = ['id' => 'operations', 'count' => 1, 'attention' => 0, 'next' => 'operations'];
        }

        return response()->json(['data' => $workflows, 'generated_at' => now()->toISOString()]);
    }
}
