<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldDefinition;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomFieldController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $entityType = $request->query('entity_type');

        $query = CustomFieldDefinition::withoutTenantScope()->where('tenant_id', $tenantId);
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        $fields = $query->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($fields);
    }

    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $data = Validator::make($request->all(), [
            'entity_type' => 'required|string|in:product,variant,customer,supplier,sale,sale_line,purchase_line,expense',
            'code' => 'required|string|max:50',
            'label' => 'required|string|max:100',
            'type' => 'required|string|in:text,number,decimal,date,boolean,select,multi_select,file',
            'required' => 'nullable|boolean',
            'default_value' => 'nullable',
            'validation' => 'nullable|array',
            'visible_when' => 'nullable|array',
            'searchable' => 'nullable|boolean',
            'show_in_list' => 'nullable|boolean',
            'show_on_receipt' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ])->validate();

        $field = CustomFieldDefinition::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entity_type' => $data['entity_type'], 'code' => $data['code']],
            [
                'label' => $data['label'],
                'type' => $data['type'],
                'required' => $data['required'] ?? false,
                'default_value' => $data['default_value'] ?? null,
                'validation' => $data['validation'] ?? null,
                'visible_when' => $data['visible_when'] ?? null,
                'searchable' => $data['searchable'] ?? false,
                'show_in_list' => $data['show_in_list'] ?? false,
                'show_on_receipt' => $data['show_on_receipt'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
            ]
        );

        return ApiResponse::success($field, [], 201);
    }
}
