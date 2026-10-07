<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnownDevice extends Model
{
    protected $fillable = ['merchant_id', 'device_signature', 'ip_address', 'user_agent', 'last_login_at'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
