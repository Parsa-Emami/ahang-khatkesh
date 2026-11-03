<?php

namespace App\Actions\Playback;

use App\Actions\LockCurrentAccount;
use App\Exceptions\OfflineDisabled;
use App\Models\OfflineDownload;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManageOfflineDownloads
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function assertEnabled(User $user): void
    {
        if (! $user->offline_opt_in || $user->offline_revocation_acknowledged_at === null) {
            throw new OfflineDisabled;
        }
    }

    /**
     * @return Collection<int, OfflineDownload>
     */
    public function list(User $snapshot): Collection
    {
        $user = $this->accounts->handle($snapshot);
        $this->assertEnabled($user);

        return OfflineDownload::where('user_id', $user->id)->orderByDesc('id')->get();
    }

    public function grant(User $snapshot, Track $track): OfflineDownload
    {
        return DB::transaction(function () use ($snapshot, $track): OfflineDownload {
            $user = $this->accounts->handle($snapshot);
            $this->assertEnabled($user);
            abort_unless(Track::visibleTo($user)->whereKey($track->id)->exists(), 404);
            $track->loadMissing(['mediaAsset.blob']);
            $asset = $track->mediaAsset;
            abort_unless($asset !== null, 422, __('فایل رسانه برای این آهنگ موجود نیست.'));
            $bytes = (int) $asset->size_bytes;
            $existing = OfflineDownload::where('user_id', $user->id)->where('track_id', $track->id)->lockForUpdate()->first();
            if ($existing && $existing->status !== OfflineDownload::REVOKED) {
                return $existing;
            }
            $used = (int) OfflineDownload::where('user_id', $user->id)
                ->whereIn('status', [OfflineDownload::PENDING, OfflineDownload::READY])
                ->sum('bytes');
            $quota = (int) ($user->offline_quota_bytes ?: config('playback.default_offline_quota_bytes'));
            abort_unless($used + $bytes <= $quota, 422, __('سهمیهٔ آفلاین پر است.'));
            if ($existing) {
                $existing->forceFill([
                    'asset_sha256' => $asset->sha256,
                    'bytes' => $bytes,
                    'status' => OfflineDownload::READY,
                    'granted_at' => now(),
                    'revoked_at' => null,
                ])->save();

                return $existing->fresh();
            }

            return OfflineDownload::create([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'asset_sha256' => $asset->sha256,
                'bytes' => $bytes,
                'status' => OfflineDownload::READY,
                'granted_at' => now(),
            ]);
        }, 5);
    }

    public function revoke(User $snapshot, Track $track): void
    {
        DB::transaction(function () use ($snapshot, $track): void {
            $user = $this->accounts->handle($snapshot);
            $row = OfflineDownload::where('user_id', $user->id)->where('track_id', $track->id)->lockForUpdate()->first();
            if (! $row || $row->status === OfflineDownload::REVOKED) {
                return;
            }
            $row->forceFill([
                'status' => OfflineDownload::REVOKED,
                'revoked_at' => now(),
            ])->save();
        }, 5);
    }

    public function wipe(User $snapshot): int
    {
        return DB::transaction(function () use ($snapshot): int {
            $user = $this->accounts->handle($snapshot);
            $count = OfflineDownload::where('user_id', $user->id)
                ->where('status', '!=', OfflineDownload::REVOKED)
                ->update(['status' => OfflineDownload::REVOKED, 'revoked_at' => now()]);

            return $count;
        }, 5);
    }

    public function usage(User $user): array
    {
        $used = (int) OfflineDownload::where('user_id', $user->id)
            ->whereIn('status', [OfflineDownload::PENDING, OfflineDownload::READY])
            ->sum('bytes');
        $quota = (int) ($user->offline_quota_bytes ?: config('playback.default_offline_quota_bytes'));

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, $quota - $used),
            'revocation_limit' => 'Fully offline devices cannot learn revoke, logout, or access_version bumps until they reconnect. Cached private audio may remain until then.',
        ];
    }
}
