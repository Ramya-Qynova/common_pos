<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\SaleService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales) {}

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'branch_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'register_id' => 'required|integer',
            'cashier_shift_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'price_list_id' => 'required|integer',
            'is_test' => 'nullable|boolean',
            'uuid' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.unit_id' => 'required|integer',
            'lines.*.quantity' => 'required|string',
            'lines.*.line_discount.type' => 'nullable|in:none,percent,amount',
            'lines.*.line_discount.value' => 'nullable|string',
            'invoice_discount.type' => 'nullable|in:none,percent,amount',
            'invoice_discount.value' => 'nullable|string',
            'payments' => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer',
            'payments.*.amount' => 'required|string',
            // Note: no `unit_price`/`amount`/`grand_total` field is accepted
            // on the sale or any line - G1: the server never reads a
            // client-submitted price. A client that sends one is simply
            // ignored, not rejected, since extra JSON keys are harmless.
        ])->validate();

        $user = $request->user('tenant');
        $sale = $this->sales->create(app(TenantContext::class)->tenantId(), $data, $user->id, $user->hasPermission('pos.sales.discount'));

        return ApiResponse::success([
            'id' => $sale->id,
            'uuid' => $sale->uuid,
            'invoice' => $sale->invoice->full_number,
            'grand_total' => $sale->grand_total,
            'payment_status' => $sale->payment_status,
            'items' => $sale->items->map(fn ($i) => [
                'product_id' => $i->product_id, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                'line_total' => $i->line_total,
            ]),
        ], [], 201);
    }
}
