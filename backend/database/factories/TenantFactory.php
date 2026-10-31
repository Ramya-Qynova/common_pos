<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'shop_code' => Str::upper(fake()->unique()->bothify('SHOP-####')),
            'name' => fake()->company(),
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }
}
