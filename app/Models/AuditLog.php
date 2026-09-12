<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = ['admin_id', 'action', 'target_type', 'target_id', 'changes', 'ip_address'];
    protected $casts = ['changes' => 'array'];
}
