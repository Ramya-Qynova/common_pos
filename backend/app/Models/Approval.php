<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $fillable = ['approval_request_id', 'approver_id', 'decision', 'pin_verified', 'decided_at'];

    protected function casts(): array
    {
        return ['pin_verified' => 'boolean', 'decided_at' => 'datetime'];
    }
}
