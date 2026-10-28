<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\TrashCatalogItem;
use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['track', 'playlist'])]]);
        $class = $data['type'] === 'track' ? Track::class : Playlist::class;
        $page = $class::onlyTrashed()->orderByDesc('deleted_at')->orderByDesc('id')->paginate(30);
        $page->through(fn ($item) => ['id' => $item->id, 'type' => $data['type'], 'title' => $item instanceof Track ? $item->title : $item->name, 'revision' => (int) ($item instanceof Track ? $item->catalog_revision : $item->revision), 'deleted_at' => $item->deleted_at->toIso8601String()]);

        return response()->json($page);
    }

    public function track(Request $request, Track $track, TrashCatalogItem $trash): JsonResponse
    {
        return $this->mutate($request, $track, $trash);
    }

    public function playlist(Request $request, Playlist $playlist, TrashCatalogItem $trash): JsonResponse
    {
        return $this->mutate($request, $playlist, $trash);
    }

    private function mutate(Request $request, Track|Playlist $item, TrashCatalogItem $trash): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $updated = $trash->handle($request->user(), $item, $data['revision'], $request->isMethod('post'));

        return response()->json(['data' => ['id' => $updated->id, 'revision' => (int) ($updated instanceof Track ? $updated->catalog_revision : $updated->revision), 'deleted_at' => $updated->deleted_at?->toIso8601String()]]);
    }
}
