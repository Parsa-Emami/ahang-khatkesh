<?php

namespace App\Actions\Listening;

use App\Actions\LockCurrentAccount;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeListeningPrivacy
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $snapshot, array $data, bool $erase = false, ?string $eventId = null): User
    {
        return DB::transaction(function () use ($snapshot, $data, $erase, $eventId): User {
            $user = $this->accounts->handle($snapshot);
            abort_unless($user->listening_revision === $data['revision'], 409, __('listening.context_changed'));
            if ($eventId !== null) {
                ListeningEvent::where('user_id', $user->id)->whereKey($eventId)->firstOrFail()->delete();
            }
            if ($erase) {
                ListeningEvent::where('user_id', $user->id)->delete();
            }
            $values = ['listening_revision' => $user->listening_revision + 1];
            foreach (['history_enabled', 'recommendations_enabled', 'retention_days'] as $field) {
                if (array_key_exists($field, $data)) {
                    $values['listening_'.$field] = $data[$field];
                }
            }
            if (array_key_exists('history_enabled', $data)) {
                $values['listening_consent_at'] = $data['history_enabled'] ? ($user->listening_consent_at ?? now()) : null;
                if (! $data['history_enabled']) {
                    ListeningEvent::where('user_id', $user->id)->delete();
                }
            }
            if (array_key_exists('private_session', $data)) {
                $values['listening_private_until'] = $data['private_session'] ? now()->addHours(config('listening.private_hours')) : null;
            }
            $user->forceFill($values)->save();
            ListeningEvent::where('user_id', $user->id)->where('started_at', '<=', now()->subDays($user->listening_retention_days))->delete();

            return $user;
        }, 5);
    }
}
