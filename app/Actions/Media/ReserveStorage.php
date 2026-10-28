<?php

namespace App\Actions\Media;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\StorageReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReserveStorage
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private StorageBudget $budget) {}

    public function handle(User $actor, int $bytes, string $purpose): StorageReservation
    {
        abort_unless(in_array($purpose, ['audio', 'cover', 'conversion'], true) && $bytes > 0 && $bytes <= match ($purpose) {
            'audio' => config('music.max_upload_kilobytes') * 1024, 'conversion' => config('music.max_conversion_bytes'), default => 5242880
        }, 422);

        return DB::transaction(function () use ($actor, $bytes, $purpose): StorageReservation {
            $actor = $this->authorize->handle($actor);
            $this->budget->assertReady();
            $budget = $this->budget->snapshot();
            abort_if(StorageReservation::whereIn('status', StorageReservation::CHARGED)->count() >= config('music.max_active_reservations'), 429, 'رزروهای قبلی باید تکمیل یا آزاد شوند.');
            abort_if($bytes > $budget['quota_available_bytes'], 507, 'ظرفیت مجاز کتابخانه کافی نیست.');
            abort_if($bytes > $budget['disk_available_bytes'], 507, 'فضای امن دیسک کافی نیست.');

            return StorageReservation::create(['user_id' => $actor->id, 'access_version' => $actor->access_version, 'purpose' => $purpose, 'size_bytes' => $bytes, 'status' => 'reserved', 'expires_at' => now()->addSeconds(config('music.reservation_seconds'))]);
        }, 5);
    }
}
