<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'description' => fake()->sentence(), 'mood' => 'focus', 'status' => 'published', 'artwork_seed' => 1, 'created_by' => User::factory()];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }
}
