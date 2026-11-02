<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = Template::where('is_active', true)
            ->with(['versions' => fn ($q) => $q->where('is_published', true)->orderByDesc('version')])
            ->get()
            ->map(function (Template $t) {
                $latest = $t->latestPublished();
                $data = $latest?->data ?? [];

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'description' => $t->description,
                    'version' => $latest?->version ?? 1,
                    'modules' => $data['modules'] ?? [],
                    'units' => $data['units'] ?? [],
                    'categories' => $data['categories'] ?? [],
                    'lookups' => $data['lookups'] ?? [],
                    'custom_fields' => $data['custom_fields'] ?? [],
                    'ui_hints' => $data['ui_hints'] ?? [],
                ];
            });

        return ApiResponse::success($templates->all());
    }

    public function show(string $id)
    {
        $template = Template::where('is_active', true)
            ->where(fn ($q) => $q->where('id', $id)->orWhere('code', $id))
            ->first();

        if (! $template) {
            return ApiResponse::error('NOT_FOUND', 'Template not found.', [], 404);
        }

        $latest = $template->latestPublished();

        return ApiResponse::success([
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'version' => $latest?->version ?? 1,
            'data' => $latest?->data ?? [],
        ]);
    }
}
