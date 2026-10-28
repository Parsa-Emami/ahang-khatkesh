<?php

namespace App\Actions\Media;

use App\Jobs\CleanupUploadFiles;
use App\Models\LibrarySetting;
use App\Models\StorageReservation;
use App\Services\DatabaseQueueContract;
use Illuminate\Support\Facades\DB;

class RecoverUploads
{
    public function __construct(private DatabaseQueueContract $queue) {}

    public function handle(int $limit = 100): int
    {
        $this->queue->verify();

        return DB::transaction(function () use ($limit): int {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $reservations = StorageReservation::where(fn ($q) => $q->whereIn('status', ['reserved', 'writing'])->where('expires_at', '<=', now())->orWhere('status', 'cleanup_pending'))->orderBy('updated_at')->orderBy('id')->limit($limit)->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $reservation->update(['status' => 'cleanup_pending', 'lease_token' => null]);
                CleanupUploadFiles::dispatch($reservation->id)->onConnection('database')->beforeCommit();
            }

            return $reservations->count();
        }, 5);
    }
}
