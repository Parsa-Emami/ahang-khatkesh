<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\Media\IngestMedia;
use App\Actions\UpdatePlaylist;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Models\AuditEvent;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlaylistController extends Controller
{
    public function store(Request $request, AuthorizeLibraryWrite $authorize): PlaylistResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'mood' => ['required', Rule::in(Playlist::MOODS)]]);
        $playlist = DB::transaction(function () use ($request, $data, $authorize): Playlist {
            $authorize->handle($request->user());
            $playlist = Playlist::create($data + ['created_by' => $request->user()->id, 'status' => Playlist::DRAFT, 'artwork_seed' => random_int(0, 65535)]);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'playlist.created', 'subject_id' => (string) $playlist->id]);

            return $playlist;
        });

        return new PlaylistResource($playlist->loadCount('tracks'));
    }

    public function update(Request $request, Playlist $playlist, UpdatePlaylist $update): PlaylistResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'name' => ['sometimes', 'required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'mood' => ['sometimes', Rule::in(Playlist::MOODS)], 'status' => ['sometimes', Rule::in([Playlist::PUBLISHED, Playlist::DRAFT, Playlist::ARCHIVED])]]);

        return new PlaylistResource($update->handle($playlist, $request->user(), $data)->loadCount('tracks'));
    }

    public function tracks(Request $request, Playlist $playlist, UpdatePlaylist $update): PlaylistResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'track_ids' => ['present', 'array', 'max:500'], 'track_ids.*' => ['required', 'integer', 'distinct', 'exists:tracks,id']]);

        return new PlaylistResource($update->handle($playlist, $request->user(), ['revision' => $data['revision']], $data['track_ids'])->loadCount('tracks')->load('tracks.mediaAsset'));
    }

    public function cover(Request $request, Playlist $playlist, IngestMedia $ingest): PlaylistResource
    {
        $data = $request->validate(['reservation_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'], 'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $ingest->handle($request->user(), $data['reservation_id'], $request->file('cover'), 'cover', true, $playlist, $data['revision']);

        return new PlaylistResource($playlist->refresh()->loadCount('tracks'));
    }
}
