<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalog\ReviewMemberProposal;
use App\Actions\Catalog\SubmitMemberProposal;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberProposalResource;
use App\Models\MemberProposal;
use App\Models\Playlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MemberProposalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MemberProposalResource::collection(
            MemberProposal::query()
                ->where('proposed_by', $request->user()->id)
                ->orderByDesc('created_at')
                ->paginate(30)
        );
    }

    public function store(Request $request, SubmitMemberProposal $submit): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(MemberProposal::KINDS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'track_id' => ['required_if:kind,track_metadata,repair_report', 'integer'],
            'title' => ['required_if:kind,track_metadata', 'string', 'max:255'],
            'credits' => ['nullable', 'array', 'max:30'],
            'primary_release_id' => ['nullable'],
            'artist_id' => ['required_if:kind,artist_rename', 'integer', 'exists:artists,id'],
            'name' => ['required_if:kind,artist_rename,playlist_create', 'string', 'max:255'],
            'sort_name' => ['nullable', 'string', 'max:255'],
            'mood' => ['required_if:kind,playlist_create', Rule::in(Playlist::MOODS)],
            'track_ids' => ['required_if:kind,playlist_create', 'array', 'min:1', 'max:200'],
            'track_ids.*' => ['integer', 'distinct', 'exists:tracks,id'],
            'left_track_id' => ['required_if:kind,duplicate_report', 'integer', 'exists:tracks,id'],
            'right_track_id' => ['required_if:kind,duplicate_report', 'integer', 'exists:tracks,id', 'different:left_track_id'],
            'suggested_survivor_track_id' => ['nullable', 'integer'],
            'code' => ['required_if:kind,repair_report', Rule::in(['missing_media_file', 'broken_primary_release', 'missing_primary_credit'])],
        ]);

        return (new MemberProposalResource($submit->handle($request->user(), $data)))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, MemberProposal $proposal, ReviewMemberProposal $review): MemberProposalResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);

        return new MemberProposalResource($review->withdraw($request->user(), $proposal, $data['revision']));
    }
}
