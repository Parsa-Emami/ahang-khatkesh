<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitMemberProposalRequest;
use App\Http\Resources\MemberProposalResource;
use App\Models\MemberProposal;
use App\Services\Catalog\MemberProposals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MemberProposalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000'], 'status' => ['nullable', Rule::in(['pending', 'accepted', 'rejected'])]]);
        $query = $this->query($request);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return MemberProposalResource::collection($query->with(['submitter:id,name', 'resultPlaylist'])->orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    private function query(Request $request): Builder
    {
        $query = MemberProposal::query();
        if (! $request->routeIs('admin.proposals.*')) {
            $query->where('user_id', $request->user()->id);
        } else {
            abort_unless($request->user()->hasPermission(Permission::CatalogManage), 403);
        }

        return $query;
    }

    public function show(Request $request, string $proposalId): MemberProposalResource
    {
        return new MemberProposalResource($this->query($request)->with(['submitter:id,name', 'resultPlaylist'])->findOrFail($proposalId));
    }

    public function store(SubmitMemberProposalRequest $request, MemberProposals $proposals): MemberProposalResource
    {
        return new MemberProposalResource($proposals->submit($request->user(), $request->validated())->load(['submitter:id,name', 'resultPlaylist']));
    }

    public function reject(Request $request, string $proposalId, MemberProposals $proposals): MemberProposalResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return new MemberProposalResource($proposals->reject($request->user(), $proposalId, (int) $data['revision'], $data['reason'])->load(['submitter:id,name', 'resultPlaylist']));
    }
}
