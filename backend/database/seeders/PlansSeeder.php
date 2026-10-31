<?php

namespace Database\Seeders;

use App\Models\ModuleDefinition;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\PlanModule;
use Illuminate\Database\Seeder;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::updateOrCreate(
            ['code' => 'standard'],
            ['name' => 'Standard', 'description' => 'Full-featured Release 1 plan.', 'is_active' => true],
        );

        foreach (ModuleDefinition::pluck('code') as $moduleCode) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_code' => $moduleCode]);
        }

        foreach ([['max_users', 50], ['max_branches', 10], ['max_registers_per_branch', 5]] as [$key, $value]) {
            PlanLimit::updateOrCreate(['plan_id' => $plan->id, 'key' => $key], ['value' => $value]);
        }
    }
}
