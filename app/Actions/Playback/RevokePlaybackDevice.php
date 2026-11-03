<?php

namespace App\Actions\Playback;

use App\Actions\LockCurrentAccount;
use App\Models\PlaybackDevice;
use App\Models\PlaybackDeviceCommand;
use App\Models\PlaybackLease;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokePlaybackDevice
{
    public function __construct(
        private LockCurrentAccount $accounts,
        private EnqueueDeviceCommand $commands,
    ) {}

    public function handle(User $snapshot, PlaybackDevice $device): void
    {
        DB::transaction(function () use ($snapshot, $device): void {
            $user = $this->accounts->handle($snapshot);
            abort_unless((int) $device->user_id === (int) $user->id, 404);
            $locked = PlaybackDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                return;
            }
            $locked->forceFill([
                'revoked_at' => now(),
                'revision' => $locked->revision + 1,
            ])->save();
            PlaybackDeviceCommand::where('target_device_id', $locked->id)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);
            $lease = PlaybackLease::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $lease) {
                return;
            }
            if ($lease->device_id === $locked->id || $lease->transfer_target_id === $locked->id) {
                $lease->forceFill([
                    'device_id' => $lease->device_id === $locked->id ? null : $lease->device_id,
                    'generation' => $lease->generation + 1,
                    'lease_token' => $lease->device_id === $locked->id ? null : $lease->lease_token,
                    'state' => $lease->device_id === $locked->id ? PlaybackLease::IDLE : $lease->state,
                    'transfer_target_id' => null,
                    'transfer_expires_at' => null,
                    'lease_expires_at' => $lease->device_id === $locked->id ? null : $lease->lease_expires_at,
                    'revision' => $lease->revision + 1,
                ])->save();
            }
            $this->commands->enqueue($user, null, $locked, 'revoke', ['reason' => 'revoked'], $lease->generation);
        }, 5);
    }
}
