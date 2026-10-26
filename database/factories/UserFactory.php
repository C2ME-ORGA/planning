<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Ouvrier,
            'is_active' => true,
            'email_verified_at' => now(),
            'activated_at' => now(),
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

    /**
     * Compte invité, pas encore activé.
     */
    public function unactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'activated_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Désactivation de test.',
        ]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function bureauDirection(): static
    {
        return $this->role(Role::BureauDirection);
    }

    public function conducteur(): static
    {
        return $this->role(Role::Conducteur);
    }

    public function chefEquipe(): static
    {
        return $this->role(Role::ChefEquipe);
    }

    public function ouvrier(): static
    {
        return $this->role(Role::Ouvrier);
    }

    /**
     * Compte avec une double authentification TOTP déjà configurée.
     */
    public function withMultiFactorAuthentication(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => [],
        ]);
    }
}
