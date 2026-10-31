<?php

namespace App\Services\Auth;

/**
 * Plain-text token values, returned to the client exactly once at issuance
 * (G15 - never stored in plain text, never returned again on a later read).
 * Only the SHA-256 hashes are persisted (device_sessions / platform_sessions).
 */
final class TokenPair
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly \DateTimeInterface $accessExpiresAt,
        public readonly \DateTimeInterface $refreshExpiresAt,
    ) {}

    public static function generate(int $accessMinutes, int $refreshDays): self
    {
        return new self(
            bin2hex(random_bytes(32)),
            bin2hex(random_bytes(32)),
            now()->addMinutes($accessMinutes),
            now()->addDays($refreshDays),
        );
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
