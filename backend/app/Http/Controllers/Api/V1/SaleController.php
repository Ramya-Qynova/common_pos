<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sales\SaleService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales) {}

    public function index(Request $request)
    {
        $query = Sale::query()
            ->with(['invoice', 'customer', 'user:id,name,username', 'payments.paymentMethod'])
            ->orderBy('id', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('server_created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('server_created_at', '<=', $dateTo);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('invoice', fn ($iq) => $iq->where('full_number', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $perPage = min((int) ($request->query('per_page', 20)), 100);
        $paginated = $query->paginate($perPage);

        return ApiResponse::success(
            $paginated->getCollection()->map(function ($s) {
                $customer = $s->customer;
                $user = $s->user;
                $userName = $user instanceof User ? ($user->name ?? $user->username) : 'Cashier';

                return [
                    'id' => $s->id,
                    'uuid' => $s->uuid,
                    'invoice_number' => $s->invoice?->full_number,
                    'status' => $s->status,
                    'payment_status' => $s->payment_status,
                    'grand_total' => bcadd((string) $s->grand_total, '0', 4),
                    'line_subtotal' => bcadd((string) $s->line_subtotal, '0', 4),
                    'tax_total' => bcadd((string) $s->tax_total, '0', 4),
                    'discount_total' => bcadd((string) $s->discount_total, '0', 4),
                    'customer' => $customer instanceof Customer ? [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                    ] : null,
                    'cashier' => $userName,
                    'payments' => collect($s->payments)->map(function ($p) {
                        $pm = $p->paymentMethod;

                        return [
                            'amount' => (string) $p->amount,
                            'change_given' => (string) $p->change_given,
                            'method' => $pm instanceof PaymentMethod ? $pm->code : 'cash',
                        ];
                    })->all(),
                    'created_at' => (string) ($s->server_created_at ?? $s->created_at),
                ];
            }),
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
        $sale = Sale::with([
            'invoice',
            'customer',
            'user:id,name,username',
            'items.product',
            'items.unit',
            'items.taxes',
            'payments.paymentMethod',
            'discounts',
        ])->find($id);

        if (! $sale) {
            return ApiResponse::error('NOT_FOUND', 'Sale not found', 404, [], [], 'errors.sale_not_found');
        }

        $customer = $sale->customer;
        $user = $sale->user;
        $userName = $user instanceof User ? ($user->name ?? $user->username) : 'Cashier';

        return ApiResponse::success([
            'id' => $sale->id,
            'uuid' => $sale->uuid,
            'invoice_number' => $sale->invoice?->full_number,
            'status' => $sale->status,
            'payment_status' => $sale->payment_status,
            'grand_total' => bcadd((string) $sale->grand_total, '0', 4),
            'line_subtotal' => bcadd((string) $sale->line_subtotal, '0', 4),
            'taxable_amount' => bcadd((string) $sale->taxable_amount, '0', 4),
            'tax_total' => bcadd((string) $sale->tax_total, '0', 4),
            'discount_total' => bcadd((string) $sale->discount_total, '0', 4),
            'customer' => $customer instanceof Customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ] : null,
            'cashier' => $userName,
            'items' => collect($sale->items)->map(function ($item) {
                $product = $item->product;
                $unit = $item->unit;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $product instanceof Product ? $product->name : 'Product',
                    'unit' => $unit instanceof Unit ? $unit->code : 'pcs',
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                    'tax_amount' => (string) $item->tax_amount,
                    'line_discount_amount' => (string) $item->line_discount_amount,
                ];
            })->all(),
            'payments' => collect($sale->payments)->map(function ($p) {
                $pm = $p->paymentMethod;

                return [
                    'id' => $p->id,
                    'amount' => (string) $p->amount,
                    'change_given' => (string) $p->change_given,
                    'method' => $pm instanceof PaymentMethod ? $pm->code : 'cash',
                    'method_name' => $pm instanceof PaymentMethod ? $pm->name : 'Cash',
                ];
            })->all(),
            'created_at' => (string) ($sale->server_created_at ?? $sale->created_at),
        ]);
    }

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
