<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'rule_type', 'rule_id', 'action_type', 'action_payload', 'requested_by', 'status'];

    protected function casts(): array
    {
        return ['action_payload' => 'array'];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
