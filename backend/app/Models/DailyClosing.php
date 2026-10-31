<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $business_date
 */
class DailyClosing extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'branch_id', 'business_date', 'status',
        'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'reopened'], true) && $this->status === 'closed';
    }
}
