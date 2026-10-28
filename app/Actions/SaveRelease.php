<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRelease
{
    public function __construct(private AuthorizeLibraryWrite $authorize) {}

    public function handle(User $actor, array $data, ?Release $release = null): Release
    {
        return DB::transaction(function () use ($actor, $release, $data): Release {
            $actor = $this->authorize->handle($actor);
            $model = $release?->exists ? Release::whereKey($release->id)->lockForUpdate()->firstOrFail() : new Release;
            if ($model->exists) {
                abort_unless((int) $model->revision === (int) $data['revision'], 409, 'اطلاعات انتشار تغییر کرده است.');
            }
            $model->fill(collect($data)->only(['title', 'type', 'released_on'])->all());
            $model->revision = ($model->revision ?? 0) + 1;
            $model->save();
            $model->artists()->detach();
            foreach ($data['artist_ids'] as $i => $id) {
                $model->artists()->attach($id, ['position' => $i + 1]);
            }
            foreach ($model->tracks()->where('primary_release_id', $model->id)->get() as $track) {
                $track->update(['album' => $model->title, 'catalog_revision' => $track->catalog_revision + 1]);
            }
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.release_saved', 'subject_type' => 'release', 'subject_id' => (string) $model->id, 'metadata' => ['revision' => $model->revision]]);

            return $model;
        }, 5);
    }
}
