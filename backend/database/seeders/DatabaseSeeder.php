<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Platform-level catalogue data only (rule 1.11/13: "Seeders insert real
 * default data rows... not code constants"). No tenant, no business
 * records - a tenant only ever comes into existence through the real
 * onboarding flow (OnboardingService), never a seeder row.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformRolesSeeder::class,
            PermissionsSeeder::class,
            ModuleDefinitionsSeeder::class,
            PlansSeeder::class,
            TemplatesSeeder::class,
            LanguagesSeeder::class,
            PlatformSettingsSeeder::class,
        ]);
    }
}
