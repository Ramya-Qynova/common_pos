<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applies the tenant isolation rule (G2, G14) at the model layer: every
 * query against a tenant-owned model is automatically scoped to
 * TenantContext::tenantId(), and new models get tenant_id filled in
 * automatically. A foreign-tenant ID therefore behaves like it doesn't
 * exist (404), never like a permission error (403) that would confirm it
 * exists in another tenant.
 *
 * When no tenant context is set (console commands, seeders, the platform
 * guard) the scope is simply not applied - those callers are expected to
 * filter by tenant_id explicitly when they need to, e.g. onboarding code
 * creating the very first rows for a brand new tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, $model): void
            {
                $tenantId = app(TenantContext::class)->tenantId();
                if ($tenantId !== null) {
                    $builder->where($model->getTable().'.tenant_id', $tenantId);
                }
            }
        });

        static::creating(function ($model) {
            if (! $model->getAttribute('tenant_id')) {
                $tenantId = app(TenantContext::class)->tenantId();
                if ($tenantId !== null) {
                    $model->setAttribute('tenant_id', $tenantId);
                }
            }
        });
    }

    public static function withoutTenantScope()
    {
        return static::withoutGlobalScopes();
    }
}
