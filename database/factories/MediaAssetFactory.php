<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaAssetFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (MediaAsset $asset): void {
            if (! $asset->blob_id) {
                $blob = MediaBlob::firstOrCreate(['sha256' => $asset->sha256, 'size_bytes' => $asset->size_bytes], ['path' => $asset->path, 'mime_type' => $asset->mime_type, 'state' => 'ready', 'verified_at' => now()]);
                $asset->update(['blob_id' => $blob->id]);
            }
        });
    }

    public function definition(): array
    {
        return ['sha256' => hash('sha256', fake()->unique()->uuid()), 'path' => 'audio/'.fake()->uuid().'.wav', 'original_name' => 'sample.wav', 'mime_type' => 'audio/wav', 'size_bytes' => 2048, 'duration_seconds' => 120, 'uploaded_by' => User::factory()];
    }
}
