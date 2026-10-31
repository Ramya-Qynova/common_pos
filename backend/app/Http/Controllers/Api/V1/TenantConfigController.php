<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModuleDefinition;
use App\Models\TenantModule;
use App\Services\Settings\SettingsService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * section 8 "Login": the client's one call for "what this user may see" -
 * modules/menu, branches, settings, permissions. section 11 "Schema-driven
 * UI rules": the menu is built ONLY from this response, never a static
 * list in the frontend.
 */
class TenantConfigController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(Request $request)
    {
        $user = $request->user('tenant');
        $tenantId = app(TenantContext::class)->tenantId();

        $enabledCodes = TenantModule::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('is_enabled', true)
            ->pluck('module_code');

        $menu = ModuleDefinition::whereIn('code', $enabledCodes)
            ->get()
            ->flatMap(fn (ModuleDefinition $m) => $m->menu ?? [])
            ->values();

        $branches = $user->branchAccess()->with('branch')->get()->pluck('branch');

        $settingKeys = [
            'locale.currency_code', 'locale.currency_decimals', 'locale.rounding_mode',
            'locale.cash_rounding_step', 'locale.timezone', 'locale.languages',
            'sales.shift_required', 'inventory.allow_negative_stock',
        ];
        $settings = [];
        foreach ($settingKeys as $key) {
            try {
                $settings[$key] = $this->settings->get($key, $tenantId);
            } catch (\RuntimeException) {
                // Not seeded yet for this tenant/platform - omit rather than fail the whole response.
            }
        }

        return ApiResponse::success([
            'modules' => $enabledCodes->values(),
            'menu' => $menu,
            'branches' => $branches,
            'permissions' => $user->permissionCodes(),
            'settings' => $settings,
        ]);
    }
}
