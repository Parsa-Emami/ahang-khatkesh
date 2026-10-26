<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaAssetFactory extends Factory
{
    public function definition(): array
    {
        return ['sha256' => hash('sha256', fake()->unique()->uuid()), 'path' => 'audio/'.fake()->uuid().'.wav', 'original_name' => 'sample.wav', 'mime_type' => 'audio/wav', 'size_bytes' => 2048, 'duration_seconds' => 120, 'uploaded_by' => User::factory()];
    }
}
