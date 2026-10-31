<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Catalogue\VariableWeightBarcodeService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BarcodeController extends Controller
{
    public function __construct(private VariableWeightBarcodeService $barcodes) {}

    public function resolve(Request $request)
    {
        $data = Validator::make($request->all(), ['code' => 'required|string'])->validate();

        $result = $this->barcodes->resolve(app(TenantContext::class)->tenantId(), $data['code']);
        if (! $result) {
            throw new ApiException('NOT_FOUND', 'No product matches this barcode.', 404);
        }

        return ApiResponse::success($result);
    }
}
