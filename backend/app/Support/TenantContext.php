<?php

namespace App\Support;

/**
 * The ONLY source of "which tenant is this request for" (G2 - never the
 * request body, URL, or headers). Set once per request, in
 * ResolveTenantContext middleware, from the authenticated tenant user.
 * Bound as a singleton so every part of the request (models' global scope,
 * services, controllers) reads the same value.
 */
class TenantContext
{
    private ?int $tenantId = null;

    private ?int $userId = null;

    private ?int $branchId = null;

    public function setTenant(int $tenantId, int $userId): void
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function setBranch(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }
}
