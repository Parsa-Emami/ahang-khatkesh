<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Imports\QueueImport;
use App\Actions\PreviewPlaylistImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Models\PlaylistImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ImportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(PlaylistImport::orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    public function store(StoreImportRequest $request, PreviewPlaylistImport $preview): ImportResource
    {
        return new ImportResource($preview->handle($request->user(), $request->file('manifest'), $request->validated()));
    }

    public function show(PlaylistImport $import): ImportResource
    {
        return new ImportResource($import);
    }

    public function commit(Request $request, PlaylistImport $import, QueueImport $queue): ImportResource
    {
        $data = $request->validate(['publish' => ['required', 'boolean']]);
        $queue->handle($request->user(), $import, $data['publish']);

        return new ImportResource($import->refresh());
    }
}
