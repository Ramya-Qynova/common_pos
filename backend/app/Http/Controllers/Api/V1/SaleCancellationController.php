<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\SaleCancellationService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaleCancellationController extends Controller
{
    public function __construct(private SaleCancellationService $cancellations) {}

    public function store(Request $request, int $sale)
    {
        $data = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
        ])->validate();

        $user = $request->user('tenant');
        $cancellation = $this->cancellations->cancel(
            app(TenantContext::class)->tenantId(), $sale, $data['reason'] ?? null, $user->id, $user->hasPermission('pos.sales.cancel'),
        );

        return ApiResponse::success([
            'id' => $cancellation->id, 'uuid' => $cancellation->uuid, 'sale_id' => $cancellation->sale_id,
            'cancelled_at' => $cancellation->cancelled_at,
        ], [], 201);
    }
}
