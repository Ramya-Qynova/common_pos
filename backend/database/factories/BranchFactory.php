<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'uuid' => (string) Str::uuid(),
            'code' => Str::upper(fake()->unique()->bothify('BR-##')),
            'name' => fake()->city().' Branch',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ];
    }
}
