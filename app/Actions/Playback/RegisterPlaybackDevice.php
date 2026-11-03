<?php

namespace App\Actions\Playback;

use App\Actions\LockCurrentAccount;
use App\Models\PlaybackDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterPlaybackDevice
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $snapshot, string $deviceKey, string $sessionId, string $name, ?string $userAgent): PlaybackDevice
    {
        return DB::transaction(function () use ($snapshot, $deviceKey, $sessionId, $name, $userAgent): PlaybackDevice {
            $user = $this->accounts->handle($snapshot);
            $binding = hash_hmac('sha256', $sessionId, (string) config('app.key'));
            $device = PlaybackDevice::where('user_id', $user->id)->where('device_key', $deviceKey)->lockForUpdate()->first();
            if ($device && $device->revoked_at !== null) {
                $device->forceFill([
                    'session_binding' => $binding,
                    'name' => $name,
                    'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                    'revoked_at' => null,
                    'last_seen_at' => now(),
                    'revision' => $device->revision + 1,
                ])->save();

                return $device->fresh();
            }
            if ($device) {
                $device->forceFill([
                    'session_binding' => $binding,
                    'name' => $name,
                    'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                    'last_seen_at' => now(),
                    'revision' => $device->revision + 1,
                ])->save();

                return $device->fresh();
            }

            return PlaybackDevice::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'device_key' => $deviceKey,
                'session_binding' => $binding,
                'name' => $name,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'revision' => 1,
                'last_seen_at' => now(),
            ]);
        }, 5);
    }
}
