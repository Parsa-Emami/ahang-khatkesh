<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            if ($user->is_admin && $user->role === UserRole::Listener) {
                $user->role = UserRole::Administrator;
            }
            if ($user->role !== UserRole::Listener && ! $user->two_factor_secret) {
                $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['test-recovery-code-1', 'test-recovery-code-2'])), 'two_factor_confirmed_at' => now()]);
            }
        });
    }

    public function withoutMfa(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]));
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
