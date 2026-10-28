<?php

namespace Tests\Support;

use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Enums\UserRole;
use App\Jobs\TranscodeMedia;
use App\Models\MediaConversion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait CreatesConversions
{
    private function library(): User
    {
        config(['music.storage_headroom_bytes' => 0, 'music.conversions_enabled' => true]);
        Storage::fake('media');
        Queue::fake([TranscodeMedia::class]);

        return User::factory()->create(['role' => UserRole::Owner]);
    }

    private function source(User $owner, ?UploadedFile $file = null): array
    {
        $file ??= FlacFixture::file();
        $r = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $asset = app(IngestMedia::class)->handle($owner, $r->id, $file, reuse: false)['asset'];

        return [$asset, MediaConversion::where('source_asset_id', $asset->id)->firstOrFail()];
    }

    private function runConversion(MediaConversion $c): void
    {
        app()->call([new TranscodeMedia($c->id, $c->generation, $c->lease_token), 'handle']);
    }
}
