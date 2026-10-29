<?php

namespace App\Services\Listening;

use App\Actions\LockCurrentAccount;
use App\Models\LibrarySetting;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ListeningLedger
{
    public function __construct(private LockCurrentAccount $accounts, private ListeningCatalog $catalog) {}

    public function pseudonym(string $session): string
    {
        return hash_hmac('sha256', 'listening-session|'.$session, config('app.key'));
    }

    public function issue(User $snapshot, string $session, int $trackId, int $revision): array
    {
        return DB::transaction(function () use ($snapshot, $session, $trackId, $revision): array {
            $user = $this->accounts->handle($snapshot);
            abort_unless($revision === $user->listening_revision, 409, __('listening.context_changed'));
            if (! $user->listening_history_enabled || $user->privateListening()) {
                return ['recording' => false];
            }
            $track = $this->catalog->query($user)->with('mediaAsset')->findOrFail($trackId);
            $payload = ['u' => $user->id, 'a' => $user->access_version, 's' => $this->pseudonym($session), 'r' => $revision, 't' => $track->id, 'm' => $track->media_asset_id, 'p' => (string) Str::uuid(), 'i' => now()->timestamp];

            return ['recording' => true, 'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'play_id' => $payload['p'], 'minimum_seconds' => max(1, min(config('listening.minimum_seconds'), $track->mediaAsset->duration_seconds / 2)), 'expires_at' => now()->addSeconds(config('listening.grant_seconds'))->toISOString()];
        }, 5);
    }

    public function record(User $snapshot, string $session, array $data): array
    {
        try {
            $grant = json_decode(Crypt::decryptString($data['token']), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $error) {
            throw ValidationException::withMessages(['token' => __('listening.invalid_grant')]);
        }
        if (! is_array($grant) || count(array_intersect(['u', 'a', 's', 'r', 't', 'm', 'p', 'i'], array_keys($grant))) !== 8) {
            throw ValidationException::withMessages(['token' => __('listening.invalid_grant')]);
        }
        abort_unless($grant['u'] === $snapshot->id && $grant['a'] === $snapshot->access_version && hash_equals($grant['s'], $this->pseudonym($session)), 403);
        abort_if(now()->timestamp - $grant['i'] > config('listening.grant_seconds'), 410, __('listening.expired_grant'));

        return DB::transaction(function () use ($snapshot, $data, $grant): array {
            $user = $this->accounts->handle($snapshot);
            abort_unless($user->listening_revision === $grant['r'] && $user->listening_history_enabled && ! $user->privateListening(), 409, __('listening.context_changed'));
            $track = $this->catalog->query($user)->with('mediaAsset')->findOrFail($grant['t']);
            abort_unless($track->media_asset_id === $grant['m'], 409, __('listening.context_changed'));
            $seconds = (float) $data['listened_seconds'];
            $duration = $track->mediaAsset->duration_seconds;
            if ($seconds > $duration + 0.5 || $seconds > now()->timestamp - $grant['i'] + 3) {
                throw ValidationException::withMessages(['listened_seconds' => __('listening.invalid_time')]);
            }
            if ($seconds < max(1, min(config('listening.minimum_seconds'), $duration / 2))) {
                return ['recorded' => false];
            }
            $event = ListeningEvent::where('user_id', $user->id)->whereKey($grant['p'])->lockForUpdate()->first();
            if ($event?->ended_at) {
                return ['recorded' => true, 'play_id' => $event->id];
            }
            $seconds = max($seconds, $event?->listened_seconds ?? 0);
            $kind = $data['kind'] === 'completed' && $seconds < $duration * 0.9 ? 'skip' : $data['kind'];
            $values = ['user_id' => $user->id, 'track_id' => $track->id, 'session_pseudonym' => $grant['s'], 'started_at' => now()->setTimestamp($grant['i']), 'last_event_at' => now(), 'ended_at' => $kind === 'progress' ? null : now(), 'listened_seconds' => $seconds, 'outcome' => $kind === 'progress' ? 'started' : $kind];
            if ($event) {
                $event->update($values);
            } else {
                ListeningEvent::create(['id' => $grant['p']] + $values);
            }
            $cap = max(1, min(10000, config('listening.max_events_per_user')));
            $extra = ListeningEvent::where('user_id', $user->id)->orderByDesc('started_at')->orderByDesc('id')->skip($cap)->limit(1000)->pluck('id');
            ListeningEvent::where('user_id', $user->id)->whereIn('id', $extra)->delete();

            return ['recorded' => true, 'play_id' => $grant['p']];
        }, 5);
    }

    public function prune(int $limit = 1000): int
    {
        $deleted = 0;
        foreach (User::orderBy('id')->pluck('id') as $id) {
            $deleted += DB::transaction(function () use ($id, $limit): int {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $user = User::whereKey($id)->lockForUpdate()->first();
                if (! $user) {
                    return 0;
                }
                $query = ListeningEvent::where('user_id', $id);
                if ($user->listening_history_enabled) {
                    $query->where('started_at', '<=', now()->subDays($user->listening_retention_days));
                }
                $ids = $query->orderBy('started_at')->orderBy('id')->limit($limit)->pluck('id');

                return ListeningEvent::where('user_id', $id)->whereIn('id', $ids)->delete();
            }, 5);
        }

        return $deleted;
    }
}
