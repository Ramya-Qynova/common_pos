<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 10 "Customers" endpoint group. Read-only search for the POS
 * screen's "attach a customer to this sale" step - gated by the same
 * `pos.sales.create` permission as billing itself, not
 * `customers.manage` (reserved for actually editing a customer).
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->all(), [
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $perPage = $data['per_page'] ?? 25;

        $query = Customer::withoutTenantScope()->where('tenant_id', $tenantId)->where('is_active', true);

        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%");
            });
        }

        $paginator = $query->orderBy('name')->paginate($perPage, ['id', 'uuid', 'code', 'name', 'phone', 'credit_limit'], 'page', $data['page'] ?? 1);

        return ApiResponse::success($paginator->items(), [
            'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
        ]);
    }
}
