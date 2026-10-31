<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Finance\DailyClosingService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailyClosingController extends Controller
{
    public function __construct(private DailyClosingService $closings) {}

    public function close(Request $request)
    {
        $data = Validator::make($request->all(), [
            'branch_id' => 'required|integer',
            'business_date' => 'required|date',
        ])->validate();

        $user = $request->user('tenant');
        $closing = $this->closings->close(app(TenantContext::class)->tenantId(), $data['branch_id'], $data['business_date'], $user->id);

        return ApiResponse::success(['id' => $closing->id, 'status' => $closing->status, 'business_date' => $closing->business_date->toDateString()], [], 201);
    }

    public function reopen(Request $request, int $dailyClosing)
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|max:255',
        ])->validate();

        $user = $request->user('tenant');
        $closing = $this->closings->reopen(app(TenantContext::class)->tenantId(), $dailyClosing, $data['reason'], $user->id);

        return ApiResponse::success(['id' => $closing->id, 'status' => $closing->status]);
    }
}
