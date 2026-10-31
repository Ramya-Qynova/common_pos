<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * G6: required on POST /sales, POST /payments/intents, all sync pushes.
 * Same key + same payload replays the stored response with
 * `Idempotent-Replay: true`. Same key + different payload ->
 * IDEMPOTENCY_KEY_REUSED. A concurrent request with the same not-yet-
 * completed key -> CONFLICT (never two sales from one key racing).
 */
class EnsureIdempotent
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return ApiResponse::error('VALIDATION_FAILED', 'Idempotency-Key header is required.', 422, ['Idempotency-Key' => 'required']);
        }

        $tenantId = app(TenantContext::class)->tenantId();
        $requestHash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::withoutTenantScope()->where('tenant_id', $tenantId)->where('key', $key)->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return ApiResponse::error('IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used with a different request.', 422);
            }
            if ($existing->status === 'completed') {
                return response()->json($existing->response_body, $existing->http_status)->header('Idempotent-Replay', 'true');
            }

            return ApiResponse::error('CONFLICT', 'This request is already being processed.', 409);
        }

        try {
            IdempotencyKey::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'key' => $key, 'request_hash' => $requestHash, 'status' => 'in_progress',
            ]);
        } catch (QueryException $e) {
            return ApiResponse::error('CONFLICT', 'This request is already being processed.', 409);
        }

        $response = $next($request);

        IdempotencyKey::withoutTenantScope()->where('tenant_id', $tenantId)->where('key', $key)->update([
            'status' => 'completed',
            'response_body' => json_decode($response->getContent(), true),
            'http_status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
