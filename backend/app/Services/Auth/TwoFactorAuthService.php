<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\SecurityEvent;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorAuthService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 16): string
    {
        $secret = '';
        $max = strlen(self::BASE32_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, $max)];
        }

        return $secret;
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    public function getOtpAuthUri(string $company, string $username, string $secret): string
    {
        $label = rawurlencode($company).':'.rawurlencode($username);
        $issuer = rawurlencode($company);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Compute RFC 6238 TOTP code for a secret and timestamp.
     */
    public function calculateTotpCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $timeSlice = (int) floor($timestamp / 30);

        $secretKey = $this->base32Decode($secret);

        // Pack 64-bit integer counter in big-endian order
        $binaryTime = pack('N*', 0).pack('N*', $timeSlice);

        $hash = hash_hmac('sha1', $binaryTime, $secretKey, true);

        // Dynamic truncation
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);

        $binary = unpack('N', $truncatedHash)[1];
        $binary = $binary & 0x7FFFFFFF;

        $otp = $binary % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify code with window of 1 step before and after.
     */
    public function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $currentTime = time();
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $currentTime + ($i * 30);
            if (hash_equals($this->calculateTotpCode($secret, $checkTime), $code)) {
                return true;
            }
        }

        return false;
    }

    public function setup(User $user, string $companyName): array
    {
        $secret = $this->generateSecret(16);
        $plainRecoveryCodes = $this->generateRecoveryCodes(8);

        $hashedCodes = array_map(fn ($c) => Hash::make($c), $plainRecoveryCodes);

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = $hashedCodes;
        $user->two_factor_enabled = false;
        $user->save();

        $qrUri = $this->getOtpAuthUri($companyName, $user->username, $secret);

        return [
            'secret' => $secret,
            'qr_uri' => $qrUri,
            'recovery_codes' => $plainRecoveryCodes,
        ];
    }

    public function confirm(User $user, string $code): array
    {
        if (empty($user->two_factor_secret)) {
            throw new ApiException('TWO_FACTOR_NOT_SETUP', 'Two-factor authentication has not been initiated.', 400);
        }

        if (! $this->verifyTotpCode($user->two_factor_secret, $code)) {
            throw new ApiException('INVALID_TWO_FACTOR_CODE', 'Invalid verification code provided.', 422);
        }

        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        SecurityEvent::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'event_type' => 'two_factor_enabled',
            'created_at' => now(),
        ]);
        AuditLogger::log($user->tenant_id, $user->id, 'two_factor.enabled', 'User', $user->id);

        return [
            'enabled' => true,
            'confirmed_at' => $user->two_factor_confirmed_at->toISOString(),
        ];
    }

    public function disable(User $user, string $password): bool
    {
        if (! Hash::check($password, $user->password)) {
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid password provided to disable 2FA.', 422);
        }

        $user->two_factor_enabled = false;
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        SecurityEvent::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'event_type' => 'two_factor_disabled',
            'created_at' => now(),
        ]);
        AuditLogger::log($user->tenant_id, $user->id, 'two_factor.disabled', 'User', $user->id);

        return true;
    }

    public function createChallenge(User $user, ?string $deviceUuid, ?string $deviceName, ?string $ip, ?string $userAgent): string
    {
        $rawToken = Str::random(64);

        TwoFactorChallenge::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'challenge_token_hash' => hash('sha256', $rawToken),
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        return $rawToken;
    }

    /**
     * @return array{user: User, device_uuid: ?string, device_name: ?string, ip: ?string, user_agent: ?string}
     */
    public function verifyChallenge(string $rawToken, string $codeOrRecovery): array
    {
        $hash = hash('sha256', $rawToken);
        $challenge = TwoFactorChallenge::withoutTenantScope()
            ->where('challenge_token_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $challenge) {
            throw new ApiException('TWO_FACTOR_CHALLENGE_EXPIRED', 'Two-factor challenge has expired or is invalid.', 401);
        }

        $user = User::withoutTenantScope()->find($challenge->user_id);
        if (! $user || ! $user->is_active) {
            throw new ApiException('UNAUTHENTICATED', 'User account is inactive or not found.', 401);
        }

        $cleanInput = trim($codeOrRecovery);
        $verified = false;

        // Try TOTP code first
        if (strlen($cleanInput) === 6 && ctype_digit($cleanInput) && ! empty($user->two_factor_secret)) {
            $verified = $this->verifyTotpCode($user->two_factor_secret, $cleanInput);
        }

        // If not verified, try recovery codes
        if (! $verified && is_array($user->two_factor_recovery_codes)) {
            $remainingCodes = [];
            foreach ($user->two_factor_recovery_codes as $hashedCode) {
                if (! $verified && Hash::check($cleanInput, $hashedCode)) {
                    $verified = true;

                    // Do not retain the used recovery code
                    continue;
                }
                $remainingCodes[] = $hashedCode;
            }

            if ($verified) {
                $user->two_factor_recovery_codes = $remainingCodes;
                $user->save();
                SecurityEvent::create([
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'event_type' => 'two_factor_recovery_code_used',
                    'created_at' => now(),
                ]);
            }
        }

        if (! $verified) {
            SecurityEvent::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'event_type' => 'two_factor_failed',
                'created_at' => now(),
            ]);
            throw new ApiException('INVALID_TWO_FACTOR_CODE', 'The provided authentication code or recovery code is invalid.', 422);
        }

        $meta = [
            'user' => $user,
            'device_uuid' => $challenge->device_uuid,
            'device_name' => $challenge->device_name,
            'ip' => $challenge->ip_address,
            'user_agent' => $challenge->user_agent,
        ];

        // Consume challenge
        $challenge->delete();

        return $meta;
    }

    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::BASE32_CHARS, $base32[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }
}
