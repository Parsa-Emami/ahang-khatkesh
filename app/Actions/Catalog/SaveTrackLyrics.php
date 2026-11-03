<?php

namespace App\Actions\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\AuditEvent;
use App\Models\Track;
use App\Models\TrackLyric;
use App\Models\User;
use App\Support\Lyrics\LrcParser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaveTrackLyrics
{
    public function __construct(
        private AuthorizeLibraryWrite $authorize,
        private LrcParser $parser,
    ) {}

    public function handle(User $actor, Track $track, array $data): TrackLyric
    {
        return DB::transaction(function () use ($actor, $track, $data): TrackLyric {
            $actor = $this->authorize->handle($actor);
            $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            $lyric = TrackLyric::where('track_id', $locked->id)->lockForUpdate()->first() ?? new TrackLyric(['track_id' => $locked->id, 'revision' => 0]);
            if ($lyric->exists) {
                abort_unless((int) $lyric->revision === (int) $data['revision'], 409, __('متن هم‌زمان تغییر کرده است؛ دوباره بارگذاری کنید.'));
            }
            $format = $data['format'];
            $source = $data['source'];
            abort_unless(in_array($format, TrackLyric::FORMATS, true) && in_array($source, TrackLyric::SOURCES, true), 422);
            if ($source === 'licensed') {
                abort_if(blank($data['license_note'] ?? null), 422, __('برای منبع دارای مجوز، یادداشت مجوز لازم است.'));
            }
            try {
                $body = $this->normalizeBody($data['body'], $format, $locked->mediaAsset?->duration_seconds);
            } catch (InvalidArgumentException $exception) {
                abort(422, __($this->message($exception->getMessage())));
            }
            $lyric->fill([
                'body' => $body,
                'format' => $format,
                'source' => $source,
                'license_note' => $source === 'licensed' ? $data['license_note'] : null,
                'updated_by' => $actor->id,
            ]);
            $lyric->revision = (int) $lyric->revision + 1;
            $lyric->save();
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.lyrics_saved',
                'subject_type' => 'track',
                'subject_id' => (string) $locked->id,
                'metadata' => ['revision' => $lyric->revision, 'format' => $format, 'source' => $source],
            ]);

            return $lyric;
        }, 5);
    }

    public function erase(User $actor, Track $track, int $revision): void
    {
        DB::transaction(function () use ($actor, $track, $revision): void {
            $actor = $this->authorize->handle($actor);
            $lyric = TrackLyric::where('track_id', $track->id)->lockForUpdate()->first();
            if (! $lyric) {
                return;
            }
            abort_unless((int) $lyric->revision === $revision, 409, __('متن هم‌زمان تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $lyric->delete();
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.lyrics_deleted',
                'subject_type' => 'track',
                'subject_id' => (string) $track->id,
                'metadata' => ['revision' => $revision],
            ]);
        }, 5);
    }

    private function normalizeBody(string $raw, string $format, ?float $duration): string
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (strlen($body) > LrcParser::MAX_BYTES) {
            throw new InvalidArgumentException('lyrics_too_large');
        }
        if (preg_match('/<[^>]*>/', $body)) {
            throw new InvalidArgumentException('lyrics_markup_forbidden');
        }
        if ($format === 'plain') {
            return $this->parser->assertPlain($body);
        }
        $this->parser->parse($body, $duration);

        return $body;
    }

    private function message(string $code): string
    {
        return match ($code) {
            'lyrics_too_large' => 'حجم متن از سقف مجاز بیشتر است.',
            'lyrics_markup_forbidden' => 'HTML یا نشانه‌گذاری در متن مجاز نیست.',
            'lyrics_invalid_timestamp' => 'زمان‌بندی LRC نامعتبر است.',
            'lyrics_timestamp_out_of_range' => 'زمان LRC خارج از محدوده مجاز است.',
            'lyrics_timestamp_past_duration' => 'زمان LRC از مدت آهنگ بیشتر است.',
            default => 'متن نامعتبر است.',
        };
    }
}
