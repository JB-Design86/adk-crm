<?php

namespace Database\Factories;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password1234'),
            'role' => 'staff',
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function staff(): static
    {
        return $this->state(fn () => ['role' => 'staff']);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['is_blocked' => true, 'blocked_at' => now()]);
    }

    /** Konto mit eingerichteter Zwei-Faktor-Anmeldung. */
    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (User $user) {
            $provider = AppAuthentication::make();
            $user->saveAppAuthenticationSecret($provider->generateSecret());
            $user->saveAppAuthenticationRecoveryCodes(array_map(
                fn (string $code) => Hash::make($code),
                $provider->generateRecoveryCodes(),
            ));
        });
    }
}
