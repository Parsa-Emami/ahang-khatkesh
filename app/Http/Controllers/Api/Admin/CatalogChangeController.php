<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewCatalogChangeRequest;
use App\Http\Resources\CatalogChangeSetResource;
use App\Models\CatalogChangeSet;
use App\Services\Catalog\CatalogChanges;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogChangeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000']]);

        return CatalogChangeSetResource::collection(CatalogChangeSet::where('user_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, string $changeId): CatalogChangeSetResource
    {
        return new CatalogChangeSetResource(CatalogChangeSet::where('user_id', $request->user()->id)->findOrFail($changeId));
    }

    public function preview(PreviewCatalogChangeRequest $request, CatalogChanges $changes): CatalogChangeSetResource
    {
        return new CatalogChangeSetResource($changes->preview($request->user(), $request->validated()));
    }

    public function commit(Request $request, string $changeId, CatalogChanges $changes): CatalogChangeSetResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'snapshot_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return new CatalogChangeSetResource($changes->commit($request->user(), $changeId, (int) $data['revision'], $data['snapshot_hash']));
    }

    public function rollback(Request $request, string $changeId, CatalogChanges $changes): CatalogChangeSetResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'snapshot_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return new CatalogChangeSetResource($changes->rollback($request->user(), $changeId, (int) $data['revision'], $data['snapshot_hash']));
    }
}
