<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use Illuminate\Support\Str;

class ApiKeyService
{
    public const PREFIX = 'pos_live_';

    public function generate(int $tenantId, string $name, array $permissions = ['*'], ?string $expiresAt = null): array
    {
        $rawSecret = Str::random(32);
        $fullKey = self::PREFIX.$rawSecret;
        $keyHash = hash('sha256', $fullKey);
        $preview = substr($rawSecret, -4);

        $apiKey = ApiKey::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'key_hash' => $keyHash,
            'prefix' => self::PREFIX,
            'secret_preview' => $preview,
            'permissions' => $permissions,
            'is_active' => true,
            'expires_at' => $expiresAt ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
        ]);

        return [
            'api_key' => $apiKey,
            'plain_key' => $fullKey,
        ];
    }

    public function validate(string $plainKey): ?ApiKey
    {
        $keyHash = hash('sha256', $plainKey);

        $apiKey = ApiKey::withoutTenantScope()
            ->where('key_hash', $keyHash)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($apiKey) {
            $apiKey->update(['last_used_at' => now()]);
        }

        return $apiKey;
    }

    public function revoke(int $tenantId, int $keyId): bool
    {
        $apiKey = ApiKey::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $keyId)
            ->first();

        if (! $apiKey) {
            return false;
        }

        return (bool) $apiKey->update(['is_active' => false]);
    }
}
