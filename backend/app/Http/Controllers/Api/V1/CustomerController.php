<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Services\Customers\CustomerLedgerService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * section 10 "Customers" endpoint group.
 */
class CustomerController extends Controller
{
    public function index(Request $request, CustomerLedgerService $ledgerService)
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
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        $paginator = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $data['page'] ?? 1);

        $items = collect($paginator->items())->map(fn (Customer $c) => [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'code' => $c->code,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'credit_limit' => $c->credit_limit,
            'current_balance' => $ledgerService->currentBalance($tenantId, $c->id),
        ]);

        return ApiResponse::success($items->all(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'credit_limit' => 'nullable|string',
            'customer_group_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $code = 'CUS-'.strtoupper(Str::random(6));

        $customer = Customer::create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'customer_group_id' => $data['customer_group_id'] ?? null,
            'code' => $code,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? '0.0000',
            'is_active' => true,
        ]);

        return ApiResponse::success([
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'credit_limit' => $customer->credit_limit,
            'current_balance' => '0.0000',
        ], [], 201);
    }

    public function ledger(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $customer = Customer::withoutTenantScope()->where('tenant_id', $tenantId)->find($id);

        if (! $customer) {
            return ApiResponse::error('NOT_FOUND', 'Customer not found', 404, [], [], 'errors.customer_not_found');
        }

        $entries = CustomerLedger::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return ApiResponse::success($entries->map(fn ($e) => [
            'id' => $e->id,
            'direction' => $e->direction,
            'amount' => bcadd((string) $e->amount, '0', 4),
            'balance_after' => bcadd((string) $e->balance_after, '0', 4),
            'source_type' => $e->source_type,
            'occurred_at' => (string) ($e->occurred_at ?? $e->created_at),
        ])->all());
    }
}
