<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Models\AuditEvent;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class PlaylistController extends Controller
{
    public function update(Request $request, Playlist $playlist): PlaylistResource
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'mood' => ['sometimes', Rule::in(Playlist::MOODS)], 'status' => ['sometimes', Rule::in([Playlist::PUBLISHED, Playlist::DRAFT])]]);
        DB::transaction(function () use ($request, $playlist, $data): void {
            $playlist->update($data);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'playlist.updated', 'subject_id' => (string) $playlist->id, 'metadata' => ['fields' => array_keys($data)]]);
        });

        return new PlaylistResource($playlist->loadCount('tracks'));
    }

    public function cover(Request $request, Playlist $playlist): PlaylistResource
    {
        $request->validate(['cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('cover')->store('covers', 'media');
        $old = $playlist->cover_path;
        try {
            $playlist->update(['cover_path' => $path]);
        } catch (Throwable $error) {
            Storage::disk('media')->delete($path);
            throw $error;
        }
        if ($old) {
            Storage::disk('media')->delete($old);
        }

        return new PlaylistResource($playlist->loadCount('tracks'));
    }
}
