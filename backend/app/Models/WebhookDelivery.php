<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'uuid',
        'webhook_id',
        'event',
        'payload',
        'status',
        'response_status',
        'response_body',
        'error_message',
        'attempt',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'attempt' => 'integer',
            'response_status' => 'integer',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
