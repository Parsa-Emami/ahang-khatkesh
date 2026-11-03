<?php

namespace App\Actions\Listening;

use App\Actions\LockCurrentAccount;
use App\Actions\Playback\ManagePlaybackLease;
use App\Models\PlaybackState;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavePlayback
{
    public function __construct(
        private LockCurrentAccount $accounts,
        private ManagePlaybackLease $leases,
    ) {}

    public function handle(User $snapshot, array $data): array
    {
        return DB::transaction(function () use ($snapshot, $data): array {
            $user = $this->accounts->handle($snapshot);
            $deviceId = $data['device_id'] ?? null;
            $leaseToken = $data['lease_token'] ?? null;
            unset($data['device_id'], $data['lease_token']);
            if ($user->privateListening() || ($data['private_mode'] ?? false) || ($data['listening_revision'] ?? 0) !== $user->listening_revision) {
                return ['revision' => (int) (PlaybackState::where('user_id', $user->id)->value('revision') ?? 0), 'suppressed' => true];
            }
            unset($data['listening_revision'], $data['private_mode']);
            $this->leases->assertWriter($user, $deviceId, $leaseToken);
            $tracks = Track::visibleTo($user)->with(['mediaAsset.blob', PlaybackMedia::EAGER])->whereIn('id', $data['queue'])->get()->keyBy('id');
            if ($tracks->count() !== count($data['queue']) || ($data['track_id'] !== null && ! in_array($data['track_id'], $data['queue'], true))) {
                throw ValidationException::withMessages(['queue' => __('صف پخش شامل آهنگ نامعتبر یا منتشرنشده است.')]);
            }
            $current = $tracks->get($data['track_id']);
            $data['position_seconds'] = $current ? min($data['position_seconds'], $current->mediaAsset->duration_seconds) : 0;
            $state = PlaybackState::firstOrCreate(['user_id' => $user->id], ['queue' => [], 'revision' => 0]);
            abort_unless($state->revision === $data['revision'], 409, __('وضعیت پخش در دستگاه دیگری تغییر کرده است. صفحه را تازه کنید.'));
            $state->update(array_replace($data, ['revision' => $state->revision + 1]));

            return ['revision' => $state->revision];
        }, 5);
    }
}
