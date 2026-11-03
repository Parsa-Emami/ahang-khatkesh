<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Catalog\ReviewMemberProposal;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberProposalResource;
use App\Models\MemberProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MemberProposalAdminController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([MemberProposal::PENDING, MemberProposal::ACCEPTED, MemberProposal::REJECTED, MemberProposal::WITHDRAWN])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = MemberProposal::query()->with('proposer:id,name')->orderByDesc('created_at');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return MemberProposalResource::collection($query->paginate(30));
    }

    public function accept(Request $request, MemberProposal $proposal, ReviewMemberProposal $review): MemberProposalResource
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return new MemberProposalResource($review->accept($request->user(), $proposal, $data['revision'], $data['review_note'] ?? null)->load('proposer:id,name'));
    }

    public function reject(Request $request, MemberProposal $proposal, ReviewMemberProposal $review): MemberProposalResource
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return new MemberProposalResource($review->reject($request->user(), $proposal, $data['revision'], $data['review_note'] ?? null)->load('proposer:id,name'));
    }
}
