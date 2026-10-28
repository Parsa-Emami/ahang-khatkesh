<?php

namespace Database\Factories;

use App\Actions\EnsureTrackCatalog;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrackFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(fn ($track) => app(EnsureTrackCatalog::class)->handle($track));
    }

    public function definition(): array
    {
        return ['media_asset_id' => MediaAsset::factory(), 'title' => fake()->sentence(3), 'artist' => fake()->name(), 'album' => fake()->words(2, true)];
    }
}
