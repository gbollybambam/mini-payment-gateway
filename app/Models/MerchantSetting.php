<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSetting extends Model
{
    use HasUuids;
    
    protected $fillable = [
        'merchant_id',
        'logo_url',
        'website_url',
        'brand_name',
        'checkout_logo_url',
        'success_url',
        'cancel_url',
        'webhook_url',
        'webhook_secret',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
