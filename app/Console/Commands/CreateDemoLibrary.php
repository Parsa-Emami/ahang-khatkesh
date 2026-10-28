<?php

namespace App\Console\Commands;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Actions\RegisterTrack;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateDemoLibrary extends Command
{
    protected $signature = 'app:demo';

    protected $description = 'Create original synthetic audio and playlists in local/testing only';

    public function handle(RegisterTrack $register, ReserveStorage $reserve, IngestMedia $ingest, AuthorizeLibraryWrite $authorize): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo data is allowed only in local/testing.');

            return self::FAILURE;
        }
        $admin = User::where('role', UserRole::Owner)->where('is_active', true)->first();
        if (! $admin) {
            $this->error('Run app:create-admin first.');

            return self::FAILURE;
        }
        $titles = ['پشت پنجره', 'آهسته‌تر از شهر', 'مدارِ آرام', 'مسیر روشن', 'در امتداد شب', 'یک نفس تازه'];
        $tracks = [];
        foreach ($titles as $index => $title) {
            $wave = $this->wave(130.81 * (2 ** ($index / 12)));
            $hash = hash('sha256', $wave);
            $asset = MediaAsset::where('sha256', $hash)->first();
            if (! $asset) {
                $reservation = $reserve->handle($admin, strlen($wave), 'audio');
                $temporary = tempnam(sys_get_temp_dir(), 'ahang-demo-');
                try {
                    file_put_contents($temporary, $wave);
                    $file = new UploadedFile($temporary, 'demo-'.$index.'.wav', 'audio/wav', null, true);
                    $asset = $ingest->handle($admin, $reservation->id, $file)['asset'];
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            $tracks[] = $register->handle(['asset_id' => $asset->id, 'title' => $title, 'artist' => 'آزمایشگاه خط‌کش', 'album' => 'صداهای نمونهٔ تولیدشده']);
        }
        $lists = [['ساعتِ آبی', 'night', 'برای وقتی که شهر آرام می‌گیرد.', 0], ['تمرکز، بدون مرز', 'focus', 'چند دقیقه فاصله از شلوغی روز.', 1], ['کمی آفتاب', 'energy', 'برای قدم‌های بعدی، کمی روشن‌تر.', 2], ['خطِ آرامش', 'calm', 'صداهایی برای نفس کشیدن.', 3], ['بعد از باران', 'calm', 'یک حال تازه، یک شروع کوچک.', 4], ['مسیرِ خانه', 'other', 'آهنگ‌هایی برای خودت.', 5]];
        DB::transaction(function () use ($lists, $tracks, $admin, $authorize): void {
            $authorize->handle($admin);
            foreach ($lists as [$name, $mood, $description, $seed]) {
                $playlist = Playlist::firstOrCreate(['name' => $name, 'created_by' => $admin->id], ['description' => $description.' این مجموعه فقط شامل صدای نمونهٔ تولیدشده است.', 'mood' => $mood, 'status' => Playlist::PUBLISHED, 'artwork_seed' => $seed]);
                if ($playlist->wasRecentlyCreated) {
                    for ($i = 0; $i < 4; $i++) {
                        $playlist->tracks()->attach($tracks[($i + $seed) % count($tracks)]->id, ['position' => $i + 1]);
                    }
                }
            }
        });
        $this->info('Six demo playlists with original synthetic audio are ready. No real artist recordings are included.');

        return self::SUCCESS;
    }

    private function wave(float $frequency): string
    {
        $rate = 16000;
        $seconds = 24;
        $samples = '';
        for ($i = 0; $i < $rate * $seconds; $i++) {
            $t = $i / $rate;
            $envelope = min(1, $t / 1.5, ($seconds - $t) / 2);
            $pulse = 0.75 + 0.25 * sin(2 * M_PI * 0.12 * $t);
            $value = (sin(2 * M_PI * $frequency * $t) + 0.45 * sin(2 * M_PI * $frequency * 1.5 * $t) + 0.22 * sin(2 * M_PI * $frequency * 2 * $t)) * 3200 * $envelope * $pulse;
            $samples .= pack('v', ((int) $value) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    }
}
