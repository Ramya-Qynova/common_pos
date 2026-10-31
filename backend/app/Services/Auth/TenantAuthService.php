<?php

namespace App\Services\Auth;

use App\Exceptions\AccountLockedException;
use App\Exceptions\AuthenticationFailedException;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * section 8 "Login": rate limit -> find user via configured identifiers ->
 * verify hash -> check tenant/account state -> issue tokens -> record
 * security event. Default login identifier is shop_code + username
 * (section 1.2).
 */
class TenantAuthService
{
    public function __construct(private SettingsService $settings) {}

    public function login(string $shopCode, string $username, string $password, ?string $deviceUuid, ?string $deviceName, ?string $ip, ?string $userAgent): array
    {
        $tenant = Tenant::withoutGlobalScopes()->where('shop_code', $shopCode)->first();

        if (! $tenant || ! in_array($tenant->status, ['active', 'setup'], true)) {
            $this->recordFailure(null, null, $ip, $userAgent);
            throw new AuthenticationFailedException;
        }

        $user = User::withoutTenantScope()->where('tenant_id', $tenant->id)->where('username', $username)->first();

        if (! $user) {
            $this->recordFailure($tenant->id, null, $ip, $userAgent);
            throw new AuthenticationFailedException;
        }

        if ($user->isLocked()) {
            SecurityEvent::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'event_type' => 'login_blocked_locked',
                'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);
            throw new AccountLockedException;
        }

        if (! $user->is_active || ! Hash::check($password, $user->password)) {
            $this->recordFailure($tenant->id, $user, $ip, $userAgent);
            throw new AuthenticationFailedException;
        }

        return DB::transaction(function () use ($tenant, $user, $deviceUuid, $deviceName, $ip, $userAgent) {
            $user->failed_login_count = 0;
            $user->locked_until = null;
            $user->last_login_at = now();
            $user->save();

            $device = null;
            if ($deviceUuid) {
                $device = Device::withoutTenantScope()->where('tenant_id', $tenant->id)->where('uuid', $deviceUuid)->first();
                if (! $device) {
                    $device = Device::create([
                        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'uuid' => $deviceUuid,
                        'name' => $deviceName, 'last_seen_at' => now(),
                    ]);
                } else {
                    $device->update(['last_seen_at' => now()]);
                }
            }

            $pair = $this->issueTokens($tenant->id, $user->id, $device?->id, $ip, $userAgent);

            SecurityEvent::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'event_type' => 'login_success',
                'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);
            AuditLogger::log($tenant->id, $user->id, 'login', 'User', $user->id);

            return ['tenant' => $tenant, 'user' => $user, 'tokens' => $pair];
        });
    }

    public function refresh(string $refreshToken, ?string $ip, ?string $userAgent): array
    {
        $session = DeviceSession::withoutTenantScope()->where('refresh_token_hash', TokenPair::hash($refreshToken))->first();

        if (! $session || ! $session->isRefreshValid()) {
            throw new AuthenticationFailedException;
        }

        return DB::transaction(function () use ($session, $ip, $userAgent) {
            $session->update(['revoked_at' => now()]);

            $pair = $this->issueTokens($session->tenant_id, $session->user_id, $session->device_id, $ip, $userAgent, $session->id);

            SecurityEvent::create([
                'tenant_id' => $session->tenant_id, 'user_id' => $session->user_id, 'event_type' => 'token_rotated',
                'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);

            return ['tenant_id' => $session->tenant_id, 'user_id' => $session->user_id, 'tokens' => $pair];
        });
    }

    public function logout(string $accessToken): void
    {
        $session = DeviceSession::withoutTenantScope()->where('access_token_hash', TokenPair::hash($accessToken))->first();
        $session?->update(['revoked_at' => now()]);
    }

    public function resolveAccessToken(string $accessToken): ?DeviceSession
    {
        $session = DeviceSession::withoutTenantScope()->where('access_token_hash', TokenPair::hash($accessToken))->first();

        return $session && $session->isAccessValid() ? $session : null;
    }

    private function issueTokens(int $tenantId, int $userId, ?int $deviceId, ?string $ip, ?string $userAgent, ?int $rotatedFromId = null): TokenPair
    {
        $accessMinutes = (int) $this->settings->get('auth.access_token_minutes', $tenantId);
        $refreshDays = 30;

        $pair = TokenPair::generate($accessMinutes, $refreshDays);

        DeviceSession::create([
            'tenant_id' => $tenantId,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'access_token_hash' => TokenPair::hash($pair->accessToken),
            'refresh_token_hash' => TokenPair::hash($pair->refreshToken),
            'access_expires_at' => $pair->accessExpiresAt,
            'refresh_expires_at' => $pair->refreshExpiresAt,
            'rotated_from_id' => $rotatedFromId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        return $pair;
    }

    private function recordFailure(?int $tenantId, ?User $user, ?string $ip, ?string $userAgent): void
    {
        SecurityEvent::create([
            'tenant_id' => $tenantId, 'user_id' => $user?->id, 'event_type' => 'login_failed',
            'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
        ]);

        if (! $user) {
            return;
        }

        $maxFailed = (int) $this->settings->get('auth.max_failed_logins', $tenantId);
        $user->failed_login_count++;

        if ($user->failed_login_count >= $maxFailed) {
            $user->locked_until = now()->addMinutes(15);
            SecurityEvent::create([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'event_type' => 'account_locked',
                'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);
        }

        $user->save();
    }
}
