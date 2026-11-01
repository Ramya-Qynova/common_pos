<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockReconciliation;
use App\Services\Inventory\StockReconciliationService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StockReconciliationController extends Controller
{
    public function __construct(
        private StockReconciliationService $reconciliationService,
        private TenantContext $tenantContext
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantContext->tenantId();

        $query = StockReconciliation::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['warehouse:id,name,code', 'createdBy:id,name,username'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginated = $query->paginate($perPage);

        return ApiResponse::success(
            $paginated->getCollection()->map(fn (StockReconciliation $r) => [
                'id' => $r->id,
                'reference_number' => $r->reference_number,
                'warehouse' => $r->warehouse?->name,
                'status' => $r->status,
                'completed_at' => $r->completed_at?->toISOString(),
                'created_by' => $r->createdBy?->name ?? $r->createdBy?->username,
                'created_at' => $r->created_at->toISOString(),
                'notes' => $r->notes,
            ]),
            [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        );
    }

    public function show(int $id)
    {
        $tenantId = $this->tenantContext->tenantId();

        $reconciliation = StockReconciliation::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with([
                'warehouse',
                'createdBy:id,name,username',
                'completedBy:id,name,username',
                'items.product.baseUnit',
            ])
            ->find($id);

        if (! $reconciliation) {
            return ApiResponse::error('NOT_FOUND', 'errors.reconciliation_not_found', 'Stock reconciliation not found.', [], 404);
        }

        return ApiResponse::success([
            'id' => $reconciliation->id,
            'reference_number' => $reconciliation->reference_number,
            'warehouse' => [
                'id' => $reconciliation->warehouse->id,
                'name' => $reconciliation->warehouse->name,
            ],
            'status' => $reconciliation->status,
            'completed_at' => $reconciliation->completed_at?->toISOString(),
            'created_by' => $reconciliation->createdBy?->name ?? $reconciliation->createdBy?->username,
            'completed_by' => $reconciliation->completedBy?->name ?? $reconciliation->completedBy?->username,
            'notes' => $reconciliation->notes,
            'items' => $reconciliation->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->baseUnit?->code ?? 'pcs',
                'system_quantity' => (string) $item->system_quantity,
                'counted_quantity' => $item->counted_quantity !== null ? (string) $item->counted_quantity : null,
                'difference_quantity' => (string) $item->difference_quantity,
                'unit_cost' => (string) $item->unit_cost,
                'variance_value' => (string) $item->variance_value,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $reconciliation = $this->reconciliationService->start(
            $tenantId,
            $validated['warehouse_id'],
            $user->id,
            $validated['product_ids'] ?? null,
            $validated['notes'] ?? null
        );

        return ApiResponse::success([
            'id' => $reconciliation->id,
            'reference_number' => $reconciliation->reference_number,
            'status' => $reconciliation->status,
        ], [], 201);
    }

    public function submitCounts(int $id, Request $request)
    {
        $validated = $request->validate([
            'counts' => 'required|array|min:1',
            'counts.*.item_id' => 'required|integer',
            'counts.*.counted_quantity' => 'required|numeric|gte:0',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $reconciliation = $this->reconciliationService->submitCounts(
            $tenantId,
            $id,
            $validated['counts'],
            $user->id
        );

        return ApiResponse::success([
            'id' => $reconciliation->id,
            'reference_number' => $reconciliation->reference_number,
            'status' => $reconciliation->status,
        ]);
    }

    public function complete(int $id, Request $request)
    {
        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $reconciliation = $this->reconciliationService->complete(
            $tenantId,
            $id,
            $user->id
        );

        return ApiResponse::success([
            'id' => $reconciliation->id,
            'reference_number' => $reconciliation->reference_number,
            'status' => $reconciliation->status,
            'completed_at' => $reconciliation->completed_at?->toISOString(),
        ]);
    }

    public function cancel(int $id, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $reconciliation = $this->reconciliationService->cancel(
            $tenantId,
            $id,
            $user->id,
            $validated['reason']
        );

        return ApiResponse::success([
            'id' => $reconciliation->id,
            'reference_number' => $reconciliation->reference_number,
            'status' => $reconciliation->status,
        ]);
    }
}
