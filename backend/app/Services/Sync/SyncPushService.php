<?php

namespace App\Services\Sync;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Services\Sales\SaleService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * section 9 "Sync push": "authenticate user and device -> processed UUID
 * returns the earlier result -> recalculate with the server engine and
 * compare totals -> apply" the listed outcomes. Each operation in the
 * batch is independent (its own DB transaction, via SaleService::create()
 * - a thrown exception there rolls back only that one operation, G4) so
 * one bad operation in a batch never affects the others (section 13.2:
 * "two devices sell the last unit offline -> both accepted").
 *
 * Only `sale.create` has a real handler this phase (D- see docs/DECISIONS.md)
 * - every other type in `offline.allowed_operations` is structurally
 * accepted by the envelope/dedup/device/tenant checks below but rejected
 * with TYPE_NOT_ALLOWED_OFFLINE once it would need its own business logic.
 */
class SyncPushService
{
    public function __construct(
        private SaleService $sales,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array<int,array>  $operations
     * @return array<int,array>
     */
    public function push(int $tenantId, array $operations): array
    {
        return array_map(fn (array $op) => $this->processOne($tenantId, $op), $operations);
    }

    private function processOne(int $tenantId, array $op): array
    {
        foreach (['operation_uuid', 'type', 'device_id', 'user_id', 'client_created_at', 'payload'] as $field) {
            if (! isset($op[$field])) {
                return $this->reject($tenantId, $op, 'INVALID_STRUCTURE', "Missing field: {$field}");
            }
        }

        $existing = SyncOperation::withoutTenantScope()->where('tenant_id', $tenantId)->where('uuid', $op['operation_uuid'])->first();
        if ($existing) {
            return $this->toResponse($existing, replay: true);
        }

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if (in_array($tenant->status, ['suspended', 'closed'], true)) {
            return $this->hold($tenantId, $op, 'TENANT_SUSPENDED');
        }

        $device = Device::withoutTenantScope()->where('tenant_id', $tenantId)->where('uuid', $op['device_id'])->first();
        if (! $device) {
            return $this->reject($tenantId, $op, 'DEVICE_NOT_FOUND', 'Unknown device.');
        }

        $clientCreatedAt = Carbon::parse($op['client_created_at']);
        if ($device->is_revoked && $device->revoked_at !== null && $clientCreatedAt->gte($device->revoked_at)) {
            return $this->reject($tenantId, $op, 'DEVICE_REVOKED', 'This device was revoked before this operation was created.', $device->id);
        }

        if ($op['type'] !== 'sale.create') {
            return $this->reject($tenantId, $op, 'TYPE_NOT_ALLOWED_OFFLINE', "Operation type [{$op['type']}] has no sync handler yet.", $device->id);
        }

        return $this->processSaleCreate($tenantId, $op, $device, $clientCreatedAt);
    }

    private function processSaleCreate(int $tenantId, array $op, Device $device, Carbon $clientCreatedAt): array
    {
        $payload = $op['payload'];
        $payload['client_created_at'] = $clientCreatedAt;
        $deviceGrandTotal = $payload['device_grand_total'] ?? null;

        $stockShortfall = false;
        try {
            $sale = $this->sales->create($tenantId, $payload, (int) $op['user_id'], (bool) ($payload['has_discount_permission'] ?? false), $device->id);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'INSUFFICIENT_STOCK') {
                return $this->reject($tenantId, $op, $e->errorCode, $e->getMessage(), $device->id);
            }
            // section 13.2 "two devices sell the last unit offline -> both accepted,
            // STOCK_SHORTFALL flagged, none lost" - retry allowing negative stock for
            // this one explicit, policy-sanctioned case rather than discarding a real
            // offline sale someone already handed cash over for.
            $sale = $this->sales->create($tenantId, $payload, (int) $op['user_id'], (bool) ($payload['has_discount_permission'] ?? false), $device->id, forceAllowNegativeStock: true);
            $stockShortfall = true;
        }

        $operation = SyncOperation::create([
            'tenant_id' => $tenantId, 'uuid' => $op['operation_uuid'], 'device_id' => $device->id,
            'user_id' => $op['user_id'], 'type' => $op['type'], 'branch_id' => $payload['branch_id'] ?? null,
            'register_id' => $payload['register_id'] ?? null, 'client_created_at' => $clientCreatedAt,
            'received_at' => now(), 'status' => 'accepted',
            'result_payload' => ['sale_id' => $sale->id, 'uuid' => $sale->uuid, 'invoice' => $sale->invoice->full_number, 'grand_total' => $sale->grand_total],
            'hash' => $op['hash'] ?? null,
        ]);

        if ($stockShortfall) {
            SyncConflict::create(['tenant_id' => $tenantId, 'sync_operation_id' => $operation->id, 'type' => 'STOCK_SHORTFALL', 'details' => ['sale_id' => $sale->id]]);
        }

        if ($deviceGrandTotal !== null && bccomp((string) $deviceGrandTotal, $sale->grand_total, 4) !== 0) {
            // section 9: "prices differ -> per offline.price_mismatch_policy, difference
            // recorded." The committed sale is always the server-recalculated total (G1
            // is never relaxed for what actually gets charged/owed) - the policy setting
            // is recorded alongside the difference for a human to reconcile, not applied
            // as a silent override of the server total (see docs/DECISIONS.md).
            SyncConflict::create(['tenant_id' => $tenantId, 'sync_operation_id' => $operation->id, 'type' => 'PRICE_MISMATCH', 'details' => [
                'device_total' => (string) $deviceGrandTotal, 'server_total' => $sale->grand_total,
                'policy' => $this->settings->get('offline.price_mismatch_policy', $tenantId),
            ]]);
        }

        $skewMinutes = abs(now()->diffInMinutes($clientCreatedAt, true));
        $skewThreshold = (int) $this->settings->get('offline.clock_skew_warning_minutes', $tenantId);
        if ($skewMinutes > $skewThreshold) {
            SyncConflict::create(['tenant_id' => $tenantId, 'sync_operation_id' => $operation->id, 'type' => 'CLOCK_SKEW', 'details' => ['skew_minutes' => $skewMinutes]]);
        }

        return $this->toResponse($operation, replay: false);
    }

    private function reject(int $tenantId, array $op, string $reason, string $message, ?int $deviceId = null): array
    {
        $operation = SyncOperation::create([
            'tenant_id' => $tenantId, 'uuid' => $op['operation_uuid'] ?? (string) Str::uuid(), 'device_id' => $deviceId,
            'user_id' => $op['user_id'] ?? null, 'type' => $op['type'] ?? 'unknown',
            'client_created_at' => $op['client_created_at'] ?? now(), 'received_at' => now(),
            'status' => 'rejected', 'reject_reason' => $reason,
        ]);

        return $this->toResponse($operation, replay: false, message: $message);
    }

    private function hold(int $tenantId, array $op, string $reason): array
    {
        $operation = SyncOperation::create([
            'tenant_id' => $tenantId, 'uuid' => $op['operation_uuid'], 'device_id' => null,
            'user_id' => $op['user_id'], 'type' => $op['type'], 'client_created_at' => $op['client_created_at'],
            'received_at' => now(), 'status' => 'held', 'reject_reason' => $reason,
        ]);

        return $this->toResponse($operation, replay: false);
    }

    private function toResponse(SyncOperation $operation, bool $replay, ?string $message = null): array
    {
        return [
            'operation_uuid' => $operation->uuid, 'status' => $operation->status,
            'reject_reason' => $operation->reject_reason, 'message' => $message,
            'result' => $operation->result_payload, 'replay' => $replay,
        ];
    }
}
