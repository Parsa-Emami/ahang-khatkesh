<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\PreviewPlaylistImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\PublishPlaylistImport;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\PlaylistImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

    public function commit(Request $request, PlaylistImport $import): ImportResource
    {
        $data = $request->validate(['publish' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $import, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $locked = PlaylistImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [PlaylistImport::COMPLETED, PlaylistImport::PROCESSING], true)) {
                return;
            }
            $locked->update(['status' => PlaylistImport::PROCESSING, 'publish' => $data['publish'], 'error' => null]);
            PublishPlaylistImport::dispatch($locked->id)->onConnection('database')->beforeCommit();
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'import.queued', 'subject_id' => $locked->id]);
        }, 5);

        return new ImportResource($import->refresh());
    }
}
