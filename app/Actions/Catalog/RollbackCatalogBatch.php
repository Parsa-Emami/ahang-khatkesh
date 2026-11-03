<?php

namespace App\Actions\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\AuditEvent;
use App\Models\CatalogChangeBatch;
use App\Models\User;
use App\Services\Catalog\CatalogSnapshot;
use Illuminate\Support\Facades\DB;

class RollbackCatalogBatch
{
    public function __construct(
        private AuthorizeLibraryWrite $authorize,
        private CatalogSnapshot $snapshots,
    ) {}

    public function handle(User $actor, CatalogChangeBatch $batch): CatalogChangeBatch
    {
        return DB::transaction(function () use ($actor, $batch): CatalogChangeBatch {
            $actor = $this->authorize->handle($actor);
            $locked = CatalogChangeBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === CatalogChangeBatch::COMMITTED, 409, __('فقط دستهٔ اعمال‌شده قابل بازگشت است.'));
            abort_unless($locked->rolled_back_at === null, 409, __('این دسته قبلاً بازگردانده شده است.'));

            $snapshot = $locked->snapshot;
            $trackSnapshots = array_values($snapshot['tracks'] ?? []);
            foreach ($trackSnapshots as $trackSnapshot) {
                $this->snapshots->clearTrackRelations((int) $trackSnapshot['id']);
            }
            foreach ($trackSnapshots as $trackSnapshot) {
                $this->snapshots->restoreTrack($trackSnapshot, false);
            }
            foreach ($trackSnapshots as $trackSnapshot) {
                $this->snapshots->restoreTrack($trackSnapshot);
            }
            foreach (array_values($snapshot['artists'] ?? []) as $artistSnapshot) {
                $this->snapshots->restoreArtist($artistSnapshot);
            }

            $locked->update([
                'status' => CatalogChangeBatch::ROLLED_BACK,
                'rolled_back_at' => now(),
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.batch_rolled_back',
                'subject_type' => 'catalog_change_batch',
                'subject_id' => $locked->id,
                'metadata' => ['kind' => $locked->kind, 'plan_hash' => $locked->plan_hash],
            ]);

            return $locked->fresh();
        }, 5);
    }
}
