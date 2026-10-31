<?php

namespace Database\Seeders;

use App\Models\Platform\PlatformRole;
use Illuminate\Database\Seeder;

class PlatformRolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['super_admin', 'Super Admin'], ['support', 'Support']] as [$code, $name]) {
            PlatformRole::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
