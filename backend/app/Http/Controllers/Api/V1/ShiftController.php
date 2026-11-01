<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Services\Finance\ShiftService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 10 "POS & sales" endpoint group. Phase 3 built ShiftService with
 * full test coverage but no HTTP route - the POS screen is the first
 * caller that actually needs one.
 */
class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function current(Request $request)
    {
        $data = Validator::make($request->all(), ['register_id' => 'required|integer'])->validate();
        $tenantId = app(TenantContext::class)->tenantId();

        $shift = CashierShift::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('register_id', $data['register_id'])->where('status', 'open')->first();

        return ApiResponse::success($shift ? ['id' => $shift->id, 'opening_cash' => $shift->opening_cash, 'opened_at' => $shift->opened_at] : null);
    }

    public function open(Request $request)
    {
        $data = Validator::make($request->all(), [
            'register_id' => 'required|integer',
            'opening_cash' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $shift = $this->shifts->open(app(TenantContext::class)->tenantId(), $data['register_id'], $data['opening_cash'], $user->id);

        return ApiResponse::success(['id' => $shift->id, 'opening_cash' => $shift->opening_cash, 'opened_at' => $shift->opened_at], [], 201);
    }

    public function close(Request $request, int $shift)
    {
        $data = Validator::make($request->all(), ['counted_cash' => 'required|string'])->validate();

        $user = $request->user('tenant');
        $closed = $this->shifts->close(app(TenantContext::class)->tenantId(), $shift, $data['counted_cash'], $user->id);

        return ApiResponse::success([
            'id' => $closed->id, 'expected_cash' => $closed->expected_cash,
            'counted_cash' => $closed->closing_cash_counted, 'cash_difference' => $closed->cash_difference,
        ]);
    }
}
