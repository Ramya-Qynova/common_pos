<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
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
            'tenant_id' => Tenant::factory(),
            'uuid' => (string) Str::uuid(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('##########'),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn () => [
            'failed_login_count' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);
    }
}
