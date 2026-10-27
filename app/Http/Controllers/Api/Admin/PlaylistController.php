<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\UpdatePlaylist;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class PlaylistController extends Controller
{
    public function store(Request $request): PlaylistResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'mood' => ['required', Rule::in(Playlist::MOODS)]]);
        $playlist = DB::transaction(function () use ($request, $data): Playlist {
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

    public function cover(Request $request, Playlist $playlist, UpdatePlaylist $update): PlaylistResource
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('cover')->store('covers', 'media');
        try {
            $updated = DB::transaction(function () use ($request, $playlist, $update, $path, $data): Playlist {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $old = Playlist::whereKey($playlist->id)->lockForUpdate()->value('cover_path');
                $updated = $update->handle($playlist, $request->user(), ['revision' => $data['revision'], 'cover_path' => $path]);
                if ($old) {
                    DB::afterCommit(fn () => Storage::disk('media')->delete($old));
                }

                return $updated;
            }, 5);
        } catch (Throwable $error) {
            Storage::disk('media')->delete($path);
            throw $error;
        }

        return new PlaylistResource($updated->loadCount('tracks'));
    }
}
