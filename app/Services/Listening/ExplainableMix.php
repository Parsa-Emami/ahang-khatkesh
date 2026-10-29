<?php

namespace App\Services\Listening;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackMedia;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;

class ExplainableMix
{
    public function __construct(private ListeningCatalog $catalog, private ListeningHistory $history) {}

    private function artist(Track $track): string
    {
        return $track->artists->first() ? 'id:'.$track->artists->first()->id : 'name:'.TextNormalizer::normalize($track->artist);
    }

    public function handle(User $user, ?string $mood, int $limit): array
    {
        if (! $user->listening_recommendations_enabled) {
            return ['basis' => 'disabled', 'data' => []];
        }
        $personal = ! $user->privateListening();
        $liked = $personal ? $user->likedTracks()->with('artists')->orderByDesc('liked_tracks.created_at')->limit(100)->get() : collect();
        $recent = $personal ? $this->history->query($user)->orderByDesc('started_at')->limit(300)->get() : collect();
        $likedIds = $liked->pluck('id');
        $artists = $liked->map(fn ($track) => $this->artist($track))->all();
        $query = $this->catalog->query($user)->whereHas('playlists', fn (Builder $query) => $query->where('status', Playlist::PUBLISHED)->when($mood, fn (Builder $query) => $query->where('mood', $mood)));
        $seeds = (clone $query)->orderBy('tracks.id')->limit(config('listening.candidate_limit'))->pluck('tracks.id')->merge($likedIds)->merge($recent->pluck('track_id'))->unique();
        $tracks = $query->whereIn('tracks.id', $seeds)->with(['artists', 'mediaAsset.blob', PlaybackMedia::EAGER, 'playlists' => fn (Builder $query) => $query->where('status', Playlist::PUBLISHED)])->withExists(['likedBy as is_liked' => fn (Builder $query) => $query->where('users.id', $user->id)])->get();
        $scored = [];
        foreach ($tracks as $track) {
            $reasons = [['code' => 'curated', 'weight' => 5]];
            if ($mood) {
                $reasons[] = ['code' => 'mood', 'weight' => 20];
            }
            if ($personal && $likedIds->contains($track->id)) {
                $reasons[] = ['code' => 'liked', 'weight' => 40];
            }
            if ($personal && in_array($this->artist($track), $artists, true)) {
                $reasons[] = ['code' => 'artist', 'weight' => 24];
            }
            $plays = $recent->where('track_id', $track->id);
            if ($plays->isNotEmpty()) {
                $reasons[] = ['code' => 'history', 'weight' => min(24, $plays->count() * 8)];
                if ($plays->first()->started_at->greaterThan(now()->subDay())) {
                    $reasons[] = ['code' => 'recent', 'weight' => -20];
                }
            }
            $scored[] = ['track' => $track, 'score' => array_sum(array_column($reasons, 'weight')), 'reasons' => $reasons, 'rank' => $track->playlists->min('pivot.position') ?? PHP_INT_MAX];
        }
        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['rank'] <=> $b['rank']) ?: ($a['track']->id <=> $b['track']->id));
        $result = [];
        $artistCounts = [];
        $releaseCounts = [];
        foreach ($scored as $row) {
            $artist = $this->artist($row['track']);
            $release = $row['track']->primary_release_id;
            if (($artistCounts[$artist] ?? 0) >= 2 || ($release && ($releaseCounts[$release] ?? 0) >= 2)) {
                continue;
            }
            $artistCounts[$artist] = ($artistCounts[$artist] ?? 0) + 1;
            if ($release) {
                $releaseCounts[$release] = ($releaseCounts[$release] ?? 0) + 1;
            }
            unset($row['rank']);
            $result[] = $row;
            if (count($result) >= $limit) {
                break;
            }
        }

        return ['basis' => $personal && ($liked->isNotEmpty() || $recent->isNotEmpty()) ? 'personalized' : 'curated', 'data' => $result, 'algorithm' => 'explainable-v1', 'max_per_artist' => 2];
    }
}
