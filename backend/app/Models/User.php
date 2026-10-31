<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant guard's user model (section 5 "Identity"). Structurally separate
 * from App\Models\Platform\PlatformUser - different table, different guard.
 *
 * @property Carbon|null $locked_until
 */
class User extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'uuid', 'username', 'email', 'phone', 'password',
        'is_active', 'must_change_password', 'failed_login_count', 'locked_until', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function branchAccess(): HasMany
    {
        return $this->hasMany(UserBranchAccess::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function permissionCodes(): array
    {
        return $this->roles()
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissionCodes())
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    public function hasBranchAccess(int $branchId): bool
    {
        return $this->branchAccess()->where('branch_id', $branchId)->exists();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
