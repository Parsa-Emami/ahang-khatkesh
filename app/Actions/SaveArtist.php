<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveArtist
{
    public function handle(User $actor, array $data, ?Artist $artist = null): Artist
    {
        return DB::transaction(function () use ($actor, $artist, $data): Artist {
            LibrarySetting::whereKey(1)->increment('lock_version');
            abort_unless(User::whereKey($actor->id)->firstOrFail()->hasPermission(Permission::CatalogManage), 403);
            $model = $artist?->exists ? Artist::whereKey($artist->id)->lockForUpdate()->firstOrFail() : new Artist;
            if ($model->exists) {
                abort_unless((int) $model->revision === (int) $data['revision'], 409, 'اطلاعات هنرمند تغییر کرده است.');
            }
            $model->fill(collect($data)->except('revision')->all());
            $model->revision = ($model->revision ?? 0) + 1;
            $model->save();
            foreach ($model->tracks()->with('artists')->get() as $track) {
                $track->update(['artist' => $track->artists->whereIn('pivot.role', ['primary', 'featured'])->pluck('name')->implode('، '), 'catalog_revision' => $track->catalog_revision + 1]);
            }
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.artist_saved', 'subject_type' => 'artist', 'subject_id' => (string) $model->id, 'metadata' => ['revision' => $model->revision]]);

            return $model;
        }, 5);
    }
}
