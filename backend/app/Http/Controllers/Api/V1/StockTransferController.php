<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private StockTransferService $transferService,
        private TenantContext $tenantContext
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantContext->tenantId();

        $query = StockTransfer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'createdBy:id,name,username'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('warehouse_id')) {
            $wId = $request->query('warehouse_id');
            $query->where(function ($q) use ($wId) {
                $q->where('source_warehouse_id', $wId)->orWhere('destination_warehouse_id', $wId);
            });
        }

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where('transfer_number', 'like', $search);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginated = $query->paginate($perPage);

        return ApiResponse::success(
            $paginated->getCollection()->map(fn (StockTransfer $t) => [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'source_warehouse' => $t->sourceWarehouse?->name,
                'destination_warehouse' => $t->destinationWarehouse?->name,
                'status' => $t->status,
                'dispatched_at' => $t->dispatched_at?->toISOString(),
                'received_at' => $t->received_at?->toISOString(),
                'created_by' => $t->createdBy?->name ?? $t->createdBy?->username,
                'created_at' => $t->created_at->toISOString(),
                'notes' => $t->notes,
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

        $transfer = StockTransfer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with([
                'sourceWarehouse',
                'destinationWarehouse',
                'createdBy:id,name,username',
                'dispatchedBy:id,name,username',
                'receivedBy:id,name,username',
                'items.product.baseUnit',
            ])
            ->find($id);

        if (! $transfer) {
            return ApiResponse::error('NOT_FOUND', 'errors.transfer_not_found', 'Stock transfer not found.', [], 404);
        }

        return ApiResponse::success([
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'source_warehouse' => [
                'id' => $transfer->sourceWarehouse->id,
                'name' => $transfer->sourceWarehouse->name,
            ],
            'destination_warehouse' => [
                'id' => $transfer->destinationWarehouse->id,
                'name' => $transfer->destinationWarehouse->name,
            ],
            'status' => $transfer->status,
            'dispatched_at' => $transfer->dispatched_at?->toISOString(),
            'received_at' => $transfer->received_at?->toISOString(),
            'created_by' => $transfer->createdBy?->name ?? $transfer->createdBy?->username,
            'dispatched_by' => $transfer->dispatchedBy?->name ?? $transfer->dispatchedBy?->username,
            'received_by' => $transfer->receivedBy?->name ?? $transfer->receivedBy?->username,
            'notes' => $transfer->notes,
            'items' => $transfer->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->baseUnit?->code ?? 'pcs',
                'quantity_dispatched' => (string) $item->quantity_dispatched,
                'quantity_received' => (string) $item->quantity_received,
                'discrepancy_quantity' => (string) $item->discrepancy_quantity,
                'unit_cost' => (string) $item->unit_cost,
                'notes' => $item->notes,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'source_warehouse_id' => 'required|integer',
            'destination_warehouse_id' => 'required|integer|different:source_warehouse_id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.notes' => 'nullable|string|max:255',
            'auto_dispatch' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $transfer = $this->transferService->create(
            $tenantId,
            $validated['source_warehouse_id'],
            $validated['destination_warehouse_id'],
            $validated['items'],
            $user->id,
            $validated['notes'] ?? null,
            (bool) ($validated['auto_dispatch'] ?? false)
        );

        return ApiResponse::success([
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
        ], [], 201);
    }

    public function dispatch(int $id, Request $request)
    {
        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $transfer = $this->transferService->dispatch($tenantId, $id, $user->id);

        return ApiResponse::success([
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
            'dispatched_at' => $transfer->dispatched_at?->toISOString(),
        ]);
    }

    public function receive(int $id, Request $request)
    {
        $validated = $request->validate([
            'items' => 'nullable|array',
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.quantity_received' => 'required_with:items|numeric|gte:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $transfer = $this->transferService->receive(
            $tenantId,
            $id,
            $validated['items'] ?? null,
            $user->id,
            $validated['notes'] ?? null
        );

        return ApiResponse::success([
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
            'received_at' => $transfer->received_at?->toISOString(),
        ]);
    }

    public function cancel(int $id, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        $tenantId = $this->tenantContext->tenantId();
        $user = $request->user('tenant');

        $transfer = $this->transferService->cancel(
            $tenantId,
            $id,
            $user->id,
            $validated['reason']
        );

        return ApiResponse::success([
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
        ]);
    }
}
