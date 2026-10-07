<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    // We removed the UUID string override so it naturally uses the DB's Auto-Increment Integer
    protected $fillable = [
        'merchant_id',
        'event',
        'target_url',
        'payload',
        'response_status',
        'response_body',
        'is_successful',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_successful' => 'boolean',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
