<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

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

    // Automatically generate UUID for 'id' on creation
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
