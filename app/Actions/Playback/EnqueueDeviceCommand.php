<?php

namespace App\Actions\Playback;

use App\Models\PlaybackDevice;
use App\Models\PlaybackDeviceCommand;
use App\Models\User;

class EnqueueDeviceCommand
{
    public function enqueue(
        User $user,
        ?PlaybackDevice $source,
        PlaybackDevice $target,
        string $type,
        array $payload,
        int $leaseGeneration,
    ): PlaybackDeviceCommand {
        abort_unless((int) $target->user_id === (int) $user->id, 404);
        abort_unless($type === 'revoke' || $target->isActive(), 404);
        abort_unless(in_array($type, PlaybackDeviceCommand::TYPES, true), 422);
        if ($source) {
            abort_unless((int) $source->user_id === (int) $user->id, 404);
        }
        $sequence = (int) PlaybackDeviceCommand::where('target_device_id', $target->id)->max('sequence') + 1;

        return PlaybackDeviceCommand::create([
            'user_id' => $user->id,
            'target_device_id' => $target->id,
            'source_device_id' => $source?->id,
            'sequence' => $sequence,
            'lease_generation' => $leaseGeneration,
            'type' => $type,
            'payload' => $payload,
            'expires_at' => now()->addSeconds((int) config('playback.command_ttl_seconds')),
        ]);
    }
}
