<?php

namespace App\Actions\Playback;

use App\Actions\LockCurrentAccount;
use App\Exceptions\PlaybackDeviceFenced;
use App\Models\PlaybackDevice;
use App\Models\PlaybackDeviceCommand;
use App\Models\PlaybackLease;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagePlaybackLease
{
    public function __construct(
        private LockCurrentAccount $accounts,
        private EnqueueDeviceCommand $commands,
    ) {}

    public function claim(User $snapshot, PlaybackDevice $device): PlaybackLease
    {
        return DB::transaction(function () use ($snapshot, $device): PlaybackLease {
            $user = $this->accounts->handle($snapshot);
            $this->assertOwnActive($user, $device);
            $lease = $this->lockLease($user->id);
            $previous = $lease->device_id;
            $token = Str::random(48);
            $lease->forceFill([
                'device_id' => $device->id,
                'generation' => $lease->generation + 1,
                'lease_token' => $token,
                'state' => PlaybackLease::ACTIVE,
                'revision' => $lease->revision + 1,
                'transfer_target_id' => null,
                'transfer_expires_at' => null,
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('playback.lease_seconds')),
            ])->save();
            $this->invalidateCommands($user->id, $lease->generation);
            if ($previous && $previous !== $device->id) {
                $this->commands->enqueue($user, $device, PlaybackDevice::find($previous), 'fence', ['reason' => 'lease_claimed'], $lease->generation);
            }
            $device->forceFill(['last_seen_at' => now()])->save();

            return $lease->fresh(['device', 'transferTarget']);
        }, 5);
    }

    public function heartbeat(User $snapshot, PlaybackDevice $device, string $token): PlaybackLease
    {
        return DB::transaction(function () use ($snapshot, $device, $token): PlaybackLease {
            $user = $this->accounts->handle($snapshot);
            $this->assertOwnActive($user, $device);
            $lease = $this->lockLease($user->id);
            if (! $lease->isHeldBy($device->id, $token)) {
                throw new PlaybackDeviceFenced(__('اجارهٔ پخش این دستگاه منقضی یا واگذار شده است.'));
            }
            if ($lease->state === PlaybackLease::TRANSFERRING && $lease->transfer_expires_at?->isPast()) {
                $this->fallbackTransfer($user, $lease, $device);
            }
            $lease->forceFill([
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('playback.lease_seconds')),
                'revision' => $lease->revision + 1,
            ])->save();
            $device->forceFill(['last_seen_at' => now()])->save();

            return $lease->fresh(['device', 'transferTarget']);
        }, 5);
    }

    public function offerTransfer(User $snapshot, PlaybackDevice $source, string $token, PlaybackDevice $target): PlaybackLease
    {
        return DB::transaction(function () use ($snapshot, $source, $token, $target): PlaybackLease {
            $user = $this->accounts->handle($snapshot);
            $this->assertOwnActive($user, $source);
            $this->assertOwnActive($user, $target);
            abort_if($source->id === $target->id, 422, __('انتقال به همان دستگاه ممکن نیست.'));
            $lease = $this->lockLease($user->id);
            if (! $lease->isHeldBy($source->id, $token)) {
                throw new PlaybackDeviceFenced(__('اجارهٔ پخش این دستگاه منقضی یا واگذار شده است.'));
            }
            $lease->forceFill([
                'state' => PlaybackLease::TRANSFERRING,
                'transfer_target_id' => $target->id,
                'transfer_expires_at' => now()->addSeconds((int) config('playback.transfer_ttl_seconds')),
                'revision' => $lease->revision + 1,
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('playback.lease_seconds')),
            ])->save();
            $this->commands->enqueue($user, $source, $target, 'transfer_offer', [
                'source_device_id' => $source->id,
                'expires_at' => $lease->transfer_expires_at?->toIso8601String(),
            ], $lease->generation);

            return $lease->fresh(['device', 'transferTarget']);
        }, 5);
    }

    public function acceptTransfer(User $snapshot, PlaybackDevice $target): PlaybackLease
    {
        return DB::transaction(function () use ($snapshot, $target): PlaybackLease {
            $user = $this->accounts->handle($snapshot);
            $this->assertOwnActive($user, $target);
            $lease = $this->lockLease($user->id);
            abort_unless(
                $lease->state === PlaybackLease::TRANSFERRING
                && $lease->transfer_target_id === $target->id
                && $lease->transfer_expires_at?->isFuture(),
                409,
                __('پیشنهاد انتقال منقضی شده یا دیگر معتبر نیست.'),
            );
            $previous = $lease->device_id ? PlaybackDevice::find($lease->device_id) : null;
            $token = Str::random(48);
            $lease->forceFill([
                'device_id' => $target->id,
                'generation' => $lease->generation + 1,
                'lease_token' => $token,
                'state' => PlaybackLease::ACTIVE,
                'transfer_target_id' => null,
                'transfer_expires_at' => null,
                'revision' => $lease->revision + 1,
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('playback.lease_seconds')),
            ])->save();
            $this->invalidateCommands($user->id, $lease->generation);
            if ($previous) {
                $this->commands->enqueue($user, $target, $previous, 'transfer_accept', ['device_id' => $target->id], $lease->generation);
            }
            $target->forceFill(['last_seen_at' => now()])->save();

            return $lease->fresh(['device', 'transferTarget']);
        }, 5);
    }

    public function rejectTransfer(User $snapshot, PlaybackDevice $target): PlaybackLease
    {
        return DB::transaction(function () use ($snapshot, $target): PlaybackLease {
            $user = $this->accounts->handle($snapshot);
            $this->assertOwnActive($user, $target);
            $lease = $this->lockLease($user->id);
            abort_unless(
                $lease->state === PlaybackLease::TRANSFERRING && $lease->transfer_target_id === $target->id,
                409,
                __('پیشنهاد انتقال منقضی شده یا دیگر معتبر نیست.'),
            );
            $source = $lease->device_id ? PlaybackDevice::find($lease->device_id) : null;
            $lease->forceFill([
                'state' => PlaybackLease::ACTIVE,
                'transfer_target_id' => null,
                'transfer_expires_at' => null,
                'revision' => $lease->revision + 1,
            ])->save();
            if ($source) {
                $this->commands->enqueue($user, $target, $source, 'transfer_reject', ['device_id' => $target->id], $lease->generation);
            }

            return $lease->fresh(['device', 'transferTarget']);
        }, 5);
    }

    public function assertWriter(User $user, ?string $deviceId, ?string $token): void
    {
        $lease = PlaybackLease::where('user_id', $user->id)->first();
        if (! $lease || $lease->state === PlaybackLease::IDLE || $lease->isExpired()) {
            return;
        }
        if (! ($deviceId && $token && $lease->isHeldBy($deviceId, $token))) {
            throw new PlaybackDeviceFenced;
        }
    }

    private function fallbackTransfer(User $user, PlaybackLease $lease, PlaybackDevice $holder): void
    {
        $targetId = $lease->transfer_target_id;
        $lease->forceFill([
            'state' => PlaybackLease::ACTIVE,
            'transfer_target_id' => null,
            'transfer_expires_at' => null,
            'revision' => $lease->revision + 1,
        ])->save();
        if ($targetId) {
            $target = PlaybackDevice::find($targetId);
            if ($target) {
                $this->commands->enqueue($user, $holder, $target, 'transfer_fallback', ['reason' => 'expired'], $lease->generation);
            }
        }
    }

    private function lockLease(int $userId): PlaybackLease
    {
        $lease = PlaybackLease::where('user_id', $userId)->lockForUpdate()->first();
        if ($lease) {
            return $lease;
        }

        return PlaybackLease::create([
            'user_id' => $userId,
            'state' => PlaybackLease::IDLE,
            'generation' => 0,
            'revision' => 0,
        ]);
    }

    private function assertOwnActive(User $user, PlaybackDevice $device): void
    {
        abort_unless((int) $device->user_id === (int) $user->id && $device->isActive(), 404);
    }

    private function invalidateCommands(int $userId, int $keepGeneration): void
    {
        PlaybackDeviceCommand::where('user_id', $userId)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('lease_generation', '<', $keepGeneration)
            ->update(['invalidated_at' => now()]);
    }
}
