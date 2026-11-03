<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Catalog\CommitCatalogBatch;
use App\Actions\Catalog\PreviewCatalogBatch;
use App\Actions\Catalog\RollbackCatalogBatch;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogChangeBatchResource;
use App\Models\CatalogChangeBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogBatchController extends Controller
{
    public function store(Request $request, PreviewCatalogBatch $preview): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CatalogChangeBatch::KINDS)],
            'changes' => ['sometimes', 'array', 'max:100'],
            'survivor_track_id' => ['required_if:kind,merge_recordings', 'integer', 'exists:tracks,id'],
            'source_track_id' => ['required_if:kind,merge_recordings', 'integer', 'exists:tracks,id', 'different:survivor_track_id'],
            'survivor_revision' => ['required_if:kind,merge_recordings', 'integer', 'min:0'],
            'source_revision' => ['required_if:kind,merge_recordings', 'integer', 'min:0'],
        ]);

        return (new CatalogChangeBatchResource($preview->handle($request->user(), $data)))->response()->setStatusCode(201);
    }

    public function show(CatalogChangeBatch $batch): CatalogChangeBatchResource
    {
        return new CatalogChangeBatchResource($batch);
    }

    public function commit(Request $request, CatalogChangeBatch $batch, CommitCatalogBatch $commit): CatalogChangeBatchResource
    {
        $data = $request->validate(['plan_hash' => ['required', 'string', 'size:64']]);

        return new CatalogChangeBatchResource($commit->handle($request->user(), $batch, $data['plan_hash']));
    }

    public function rollback(Request $request, CatalogChangeBatch $batch, RollbackCatalogBatch $rollback): CatalogChangeBatchResource
    {
        return new CatalogChangeBatchResource($rollback->handle($request->user(), $batch));
    }
}
