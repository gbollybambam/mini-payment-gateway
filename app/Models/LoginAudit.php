<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginAudit extends Model
{
    protected $fillable = ['merchant_id', 'ip_address', 'user_agent', 'status'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
