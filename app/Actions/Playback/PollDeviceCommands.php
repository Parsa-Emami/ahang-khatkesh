<?php

namespace App\Actions\Playback;

use App\Actions\LockCurrentAccount;
use App\Models\PlaybackDevice;
use App\Models\PlaybackDeviceCommand;
use App\Models\PlaybackLease;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PollDeviceCommands
{
    public function __construct(private LockCurrentAccount $accounts) {}

    /**
     * @return Collection<int, PlaybackDeviceCommand>
     */
    public function handle(User $snapshot, PlaybackDevice $device): Collection
    {
        return DB::transaction(function () use ($snapshot, $device): Collection {
            $user = $this->accounts->handle($snapshot);
            abort_unless((int) $device->user_id === (int) $user->id && $device->isActive(), 404);
            $lease = PlaybackLease::where('user_id', $user->id)->lockForUpdate()->first();
            $generation = (int) ($lease?->generation ?? 0);
            $commands = PlaybackDeviceCommand::where('target_device_id', $device->id)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->where('expires_at', '>', now())
                ->where('lease_generation', $generation)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            foreach ($commands as $command) {
                $command->forceFill(['consumed_at' => now()])->save();
            }
            $device->forceFill(['last_seen_at' => now()])->save();

            return $commands;
        }, 5);
    }
}
