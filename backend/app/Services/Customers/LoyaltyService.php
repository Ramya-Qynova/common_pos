<?php

namespace App\Services\Customers;

use App\Exceptions\ApiException;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 7 "loyalty_accounts, loyalty_transactions" - basics only this
 * phase (manual earn/redeem); sale-driven accrual is wired in Phase 4.
 */
class LoyaltyService
{
    public function earn(int $tenantId, int $customerId, string $points, string $sourceType = 'manual', ?int $sourceId = null, ?string $reason = null): LoyaltyTransaction
    {
        return $this->applyTransaction($tenantId, $customerId, 'earn', $points, $sourceType, $sourceId, $reason);
    }

    public function redeem(int $tenantId, int $customerId, string $points, string $sourceType = 'manual', ?int $sourceId = null, ?string $reason = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($tenantId, $customerId, $points, $sourceType, $sourceId, $reason) {
            $account = LoyaltyAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                ->lockForUpdate()->first();

            if (! $account || bccomp($account->points_balance, $points, 4) < 0) {
                throw new ApiException('VALIDATION_FAILED', 'Cannot redeem more points than the current balance.', 422);
            }

            return $this->applyTransaction($tenantId, $customerId, 'redeem', $points, $sourceType, $sourceId, $reason);
        });
    }

    private function applyTransaction(int $tenantId, int $customerId, string $direction, string $points, string $sourceType, ?int $sourceId, ?string $reason): LoyaltyTransaction
    {
        return DB::transaction(function () use ($tenantId, $customerId, $direction, $points, $sourceType, $sourceId, $reason) {
            $account = LoyaltyAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                ->lockForUpdate()->first();

            if (! $account) {
                $account = LoyaltyAccount::withoutTenantScope()->create([
                    'tenant_id' => $tenantId, 'customer_id' => $customerId, 'points_balance' => '0.0000',
                ]);
            }

            $delta = $direction === 'earn' ? $points : bcmul($points, '-1', 4);
            $newBalance = bcadd($account->points_balance, $delta, 4);

            $transaction = LoyaltyTransaction::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'loyalty_account_id' => $account->id,
                'direction' => $direction, 'points' => $points, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'balance_after' => $newBalance, 'reason' => $reason, 'created_at' => now(),
            ]);

            $account->points_balance = $newBalance;
            $account->save();

            return $transaction;
        });
    }
}
