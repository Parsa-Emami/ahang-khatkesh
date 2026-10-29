<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrashCatalogItem
{
    public function __construct(private AuthorizeLibraryWrite $authorize) {}

    public function handle(User $actor, Track|Playlist $item, int $revision, bool $restore = false): Track|Playlist
    {
        return DB::transaction(function () use ($actor, $item, $revision, $restore): Track|Playlist {
            $actor = $this->authorize->handle($actor);
            $locked = $item::withTrashed()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $column = $locked instanceof Track ? 'catalog_revision' : 'revision';
            if ($locked->trashed() !== $restore) {
                return $locked;
            }
            abort_unless((int) $locked->$column === $revision, 409, __('اطلاعات تغییر کرده است؛ دوباره بارگذاری کنید.'));
            if ($restore && $locked instanceof Track) {
                $names = $locked->artists()->wherePivotIn('role', ['primary', 'featured'])->pluck('name');
                if ($names->isNotEmpty()) {
                    $locked->artist = $names->implode('، ');
                }
                $locked->album = $locked->primaryRelease?->title;
            }
            $locked->$column++;
            $locked->save();
            $restore ? $locked->restore() : $locked->delete();
            AuditEvent::create(['user_id' => $actor->id, 'action' => ($locked instanceof Track ? 'track.' : 'playlist.').($restore ? 'restored' : 'trashed'), 'subject_type' => $locked instanceof Track ? 'track' : 'playlist', 'subject_id' => (string) $locked->id, 'metadata' => ['revision' => (int) $locked->$column]]);

            return $locked;
        }, 5);
    }
}
