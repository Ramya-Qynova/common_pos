<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\TemplateUpdateService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class TemplateUpdateController extends Controller
{
    public function __construct(private TemplateUpdateService $templates) {}

    public function preview(Request $request)
    {
        return ApiResponse::success($this->templates->preview(app(TenantContext::class)->tenantId()));
    }

    public function apply(Request $request)
    {
        $user = $request->user('tenant');

        return ApiResponse::success($this->templates->apply(app(TenantContext::class)->tenantId(), $user->id), [], 201);
    }
}
